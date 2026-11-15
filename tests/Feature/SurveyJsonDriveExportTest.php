<?php
namespace Tests\Feature;

use App\Models\{User,Project,Circle,Division,SubDivision,GridStation,Feeder,DriveStorageConnection,DriveStorageFolder,DriveStoredFile};
use App\Services\DriveStorage\{SurveyJsonExport,DriveClient};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http,Queue,Storage,DB};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SurveyJsonDriveExportTest extends TestCase
{
    use RefreshDatabase;
    private array $remote = [], $sessions = [];
    private int $ids = 0, $uploads = 0;
    private bool $failUpload = false;
    private User $admin;
    private Feeder $feeder;

    protected function setUp(): void
    {
        parent::setUp(); Storage::fake('local'); Queue::fake();
        config(['drive_storage.enabled'=>false, 'drive_storage.folder_id'=>'unrelated-original-storage-root']);
        $this->admin=User::factory()->create(['role'=>'super_admin']); $this->actingAs($this->admin);
        DriveStorageConnection::create(['id'=>1,'account_id'=>'json-account','account_email'=>'storage@example.test','connected_by'=>$this->admin->id,
            'token'=>['access_token'=>'test-token','expires_at'=>now()->addHour()->timestamp]]);
        $p=Project::create(['code'=>'JSON','name'=>'JSON test','status'=>'active','timezone'=>'Asia/Karachi']);
        $c=Circle::create(['project_id'=>$p->id,'code'=>'C','name'=>'Circle']);
        $d=Division::create(['project_id'=>$p->id,'circle_id'=>$c->id,'code'=>'D','name'=>'Division']);
        $s=SubDivision::create(['project_id'=>$p->id,'division_id'=>$d->id,'code'=>'S','name'=>'Sub']);
        $g=GridStation::create(['project_id'=>$p->id,'sub_division_id'=>$s->id,'code'=>'G','name'=>'Grid']);
        $this->feeder=Feeder::create(['project_id'=>$p->id,'circle_id'=>$c->id,'division_id'=>$d->id,'sub_division_id'=>$s->id,'grid_station_id'=>$g->id,
            'feeder_code'=>'12803','feeder_name'=>'CITY MANSEHRA','status'=>'active','total_transformers'=>0]);
        $root=config('drive_storage.json_export_folder_id');
        $this->remote[$root]=['id'=>$root,'mimeType'=>'application/vnd.google-apps.folder','trashed'=>false];
        Http::preventStrayRequests();
        Http::fake(function ($r) {
            $url=$r->url();
            if (str_contains($url,'generateIds')) return Http::response(['ids'=>['json-drive-'.++$this->ids]]);
            if ($r->method()==='POST' && str_contains($url,'uploadType=resumable')) {
                $this->sessions[$r['id']]=$r->data();
                return Http::response([],200,['Location'=>'https://www.googleapis.com/upload/drive/v3/files?upload_id='.$r['id']]);
            }
            if ($r->method()==='PUT') {
                parse_str(parse_url($url,PHP_URL_QUERY),$q);$id=$q['upload_id'];
                if ($this->failUpload) return Http::response([],503);
                $this->uploads++;$body=$r->body();
                $this->remote[$id]=$this->sessions[$id]+['size'=>strlen($body),'md5Checksum'=>md5($body)];
                return Http::response($this->remote[$id]);
            }
            if ($r->method()==='POST' && $r['mimeType']==='application/vnd.google-apps.folder') {
                $this->remote[$r['id']]=$r->data(); return Http::response($this->remote[$r['id']]);
            }
            $id=basename(parse_url($url,PHP_URL_PATH));
            return isset($this->remote[$id])?Http::response($this->remote[$id]):Http::response([],404);
        });
    }

    public function test_json_uses_one_folder_per_feeder_under_the_requested_parent_and_preserves_other_storage(): void
    {
        $service=app(SurveyJsonExport::class);
        $first=$service->save($this->admin,$this->feeder,'{"transformer":"T1"}','transformer-T1-r1.json',[]);
        $service->save($this->admin,$this->feeder,'{"transformer":"T2"}','transformer-T2-r1.json',[]);
        $this->assertDatabaseCount('drive_storage_folders',1);$this->assertSame(2,$this->uploads);
        $folder=DriveStorageFolder::firstOrFail();
        $this->assertSame('12803-CITY MANSEHRA',$folder->name);
        $this->assertSame('1lIJSvVYucz6UWqKPCKRWYtcse-7Rgi6-',$folder->root_folder_id);
        foreach(DriveStoredFile::all() as $file){
            $this->assertSame([$folder->drive_folder_id],$this->remote[$file->drive_file_id]['parents']);
            $this->assertSame('uploaded',$file->status);Storage::disk('local')->assertMissing($file->local_path);
        }
        $this->assertSame('https://drive.google.com/drive/folders/'.$folder->drive_folder_id,$first['folder_url']);
        $again=$service->save($this->admin,$this->feeder,'{"transformer":"T1"}','transformer-T1-r1.json',[]);
        $this->assertSame($first['file_url'],$again['file_url']);$this->assertSame(2,$this->uploads);
        $other=$this->feeder->replicate();$other->feeder_code='12807';$other->feeder_name='KHAKI';$other->save();
        $service->save($this->admin,$other,'{"transformer":"T3"}','transformer-T3-r1.json',[]);
        $this->assertDatabaseCount('drive_storage_folders',2);
        $this->assertSame('unrelated-original-storage-root',config('drive_storage.folder_id'));
        Queue::assertNothingPushed();
    }

    public function test_failed_transfer_keeps_the_json_and_retry_reuses_the_folder_and_file_identity(): void
    {
        $service=app(SurveyJsonExport::class);$this->failUpload=true;
        try {$service->save($this->admin,$this->feeder,'{"transformer":"retry"}','retry.json',[]);$this->fail('Expected failure');}
        catch(ValidationException $e){$this->assertArrayHasKey('google_drive',$e->errors());}
        $file=DriveStoredFile::firstOrFail();$id=$file->drive_file_id;
        $this->assertSame('failed',$file->status);Storage::disk('local')->assertExists($file->local_path);
        $this->failUpload=false;
        $service->save($this->admin,$this->feeder,'{"transformer":"retry"}','retry.json',[]);
        $this->assertDatabaseCount('drive_stored_files',1);$this->assertDatabaseCount('drive_storage_folders',1);
        $this->assertSame($id,$file->fresh()->drive_file_id);$this->assertSame('uploaded',$file->fresh()->status);
    }

    public function test_unreadable_connection_requires_reconnection_without_uploading_anything(): void
    {
        DB::table('drive_storage_connections')->where('id',1)->update(['token'=>'unreadable-token']);
        try {app(SurveyJsonExport::class)->save($this->admin,$this->feeder,'{}','test.json',[]);$this->fail('Expected connection error');}
        catch(ValidationException $e){$this->assertStringContainsString('reconnect',$e->errors()['google_drive'][0]);}
        Http::assertNothingSent();$this->assertDatabaseCount('drive_stored_files',0);
    }
}
