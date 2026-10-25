<?php

/** Creates a standalone Word document; no application settings are changed. */
$escape = static fn (string $text): string => htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$paragraph = static function (string $text, string $style = 'Normal') use ($escape): string {
    return '<w:p><w:pPr><w:pStyle w:val="'.$style.'"/></w:pPr><w:r><w:t xml:space="preserve">'.$escape($text).'</w:t></w:r></w:p>';
};
$code = static function (array $lines) use ($paragraph): string {
    return implode('', array_map(fn ($line) => $paragraph($line, 'Code'), $lines));
};
$table = static function (array $rows) use ($paragraph): string {
    $xml = '<w:tbl><w:tblPr><w:tblW w:w="9866" w:type="dxa"/><w:tblBorders><w:top w:val="single" w:sz="4" w:color="CFD9E3"/><w:left w:val="single" w:sz="4" w:color="CFD9E3"/><w:bottom w:val="single" w:sz="4" w:color="CFD9E3"/><w:right w:val="single" w:sz="4" w:color="CFD9E3"/><w:insideH w:val="single" w:sz="4" w:color="CFD9E3"/><w:insideV w:val="single" w:sz="4" w:color="CFD9E3"/></w:tblBorders><w:tblCellMar><w:top w:w="80" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="80" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid><w:gridCol w:w="3200"/><w:gridCol w:w="6666"/></w:tblGrid>';
    foreach ($rows as $i => $row) {
        $xml .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
        foreach ($row as $j => $text) {
            $xml .= '<w:tc><w:tcPr><w:tcW w:w="'.($j ? 6666 : 3200).'" w:type="dxa"/>'.($i === 0 ? '<w:shd w:fill="E8EFF6"/>' : '').'</w:tcPr>'.$paragraph($text, $i === 0 ? 'TableHeader' : 'TableText').'</w:tc>';
        }
        $xml .= '</w:tr>';
    }

    return $xml.'</w:tbl>'.$paragraph('');
};
$body = $paragraph('Connect production to your Windows MDB worker', 'Title');
$body .= $paragraph('HTTPS and authentication setup | Saved 25 October 2026 (Asia/Karachi)', 'Subtitle');
$body .= $paragraph('Your local MDB worker can remain HTTP. Cloudflare Tunnel provides the public HTTPS address and forwards requests through an encrypted tunnel to the HTTP worker on the same Windows computer. Your local Laravel website does not need to change from HTTP to HTTPS.');
$body .= $code(['Production website on Linux/cPanel', '  -> HTTPS: https://mdb-worker.barqaab.pk', '  -> Encrypted Cloudflare Tunnel', '  -> Windows worker: http://127.0.0.1:8766']);
$body .= $paragraph('Example hostname: mdb-worker.barqaab.pk. Replace it with the hostname you actually configure. The tunnel requires a Cloudflare-managed domain; your production application can remain hosted on cPanel.');

$body .= $paragraph('1. Start the Windows MDB worker', 'Heading1');
$body .= $paragraph('The MDB worker listens on port 8766. The local Laravel application at http://127.0.0.1:8000 is a separate service. Point the tunnel at the worker, not the Laravel website.');
$body .= $paragraph('Before continuing, configure and start scripts/mdb/worker_server.py with its private shared secret, template registry and matching Windows Access DAO/ACE engine. Keep this worker running. The complete worker setup is in docs/mdb/WORKER.md in your project folder:');
$body .= $code(['D:\xampp\htdocs\survey_progress_monitoring_system\docs\mdb\WORKER.md']);
$body .= $paragraph('This guide covers the HTTPS connection. It does not install or configure an otherwise missing MDB worker.');

$body .= $paragraph('2. Create a permanent Cloudflare Tunnel', 'Heading1');
$body .= $paragraph('Use a domain managed through Cloudflare. Keep the existing cPanel website and its DNS records intact when arranging the domain setup.');
foreach ([
    'a. Sign in to Cloudflare and open Networking > Tunnels > Create a tunnel.',
    'b. Name the tunnel mdb-worker and select Windows as the operating system.',
    'c. Copy the installation command supplied by Cloudflare. Run it in PowerShell as Administrator on the Windows computer.',
    'd. Wait until the tunnel is connected and its status is Healthy.',
    'e. Open the tunnel’s Routes tab, select Add route, then Published application. Enter the settings below.',
] as $line) {
    $body .= $paragraph($line);
}
$body .= $table([
    ['Setting', 'Value'],
    ['Public hostname', 'mdb-worker.barqaab.pk (example; use your actual hostname)'],
    ['Service type', 'HTTP'],
    ['Service address', '127.0.0.1:8766'],
    ['Full local service URL', 'http://127.0.0.1:8766'],
    ['Path', 'Leave blank'],
]);
$body .= $paragraph('Save the route. Use a permanent named tunnel for this connection; temporary Quick Tunnel hostnames change and are intended for testing. No inbound router port forwarding is required for the tunnel.');

$body .= $paragraph('3. Configure shared-secret authentication', 'Heading1');
$body .= $paragraph('HTTPS encrypts the connection. The application’s existing shared-secret authentication controls who can submit MDB jobs. Set the same private secret, at least 32 characters long, in both places:');
$body .= $table([
    ['Location', 'Setting'],
    ['Windows worker environment', 'MDB_EXPORT_WORKER_SECRET'],
    ['Production application .env', 'MDB_EXPORT_WORKER_SECRET'],
]);
$body .= $paragraph('The production application automatically signs each request, and the worker checks the signature. It also signs responses for the application to verify. Keep the secret private; the placeholder in the next step must be replaced.');
$body .= $paragraph('Do not place a normal browser/email login in front of this API. Production calls it automatically. A separate Cloudflare Access service-token layer would require additional client configuration.');

$body .= $paragraph('4. Update production configuration in cPanel', 'Heading1');
$body .= $paragraph('Edit the production application’s .env file. Use your actual HTTPS hostname and the same private secret used by the Windows worker:');
$body .= $code([
    'MDB_EXPORT_DRIVER=http',
    'MDB_EXPORT_WORKER_URL=https://mdb-worker.barqaab.pk',
    'MDB_EXPORT_WORKER_SECRET=YOUR_PRIVATE_SHARED_SECRET',
    'MDB_EXPORT_TIMEOUT=180',
]);
$body .= $paragraph('Enter the base URL only. Do not append /v1/exports; the application adds that path.');
$body .= $paragraph('From the production project directory, run:');
$body .= $code(['php artisan config:cache', 'php artisan queue:restart']);
$body .= $paragraph('Ensure the production queue worker processes the mdb queue. Restarting a queue does not start a queue worker that was not already running. Your hosting administrator should configure the queue process appropriately for cPanel.');

$body .= $paragraph('5. Test HTTPS and authentication', 'Heading1');
$body .= $paragraph('On Windows, run this unsigned test request. Substitute your actual hostname:');
$body .= $code([
    'curl.exe -i -X POST "https://mdb-worker.barqaab.pk/v1/exports" ^',
    '  -H "Content-Type: application/json" --data "{}"',
]);
$body .= $paragraph('The two-line command above uses Command Prompt (cmd.exe) continuation syntax. In PowerShell, run it as one line:');
$body .= $code(['curl.exe -i -X POST "https://mdb-worker.barqaab.pk/v1/exports" -H "Content-Type: application/json" --data "{}"']);
$body .= $paragraph('Expected response for this unsigned test:');
$body .= $code(['HTTP 401', 'Worker authentication failed.']);
$body .= $paragraph('For this test, 401 is correct: it confirms the HTTPS address reaches the worker and the worker rejects unauthenticated requests. It does not yet prove that an authenticated MDB job can be completed.');
$body .= $paragraph('Finally, generate an MDB from an approved survey on the production website. The application supplies the correct authentication automatically. A successful download completes the connection test; model completeness and native SynerGEE acceptance are separate checks.');

$body .= $paragraph('Operating reminders', 'Heading1');
foreach ([
    'Keep both the Windows MDB worker and Cloudflare Tunnel running.',
    'Keep the Windows computer awake, powered on and connected while jobs are being processed.',
    'The template registered on Windows must match the code, version and file hash selected by production.',
    'This connection setup does not change Microsoft’s documented deployment limitations for the Access Database Engine.',
] as $line) {
    $body .= $paragraph('• '.$line);
}
$body .= $paragraph('Troubleshooting', 'Heading1');
$body .= $table([
    ['Result', 'What to check'],
    ['Cloudflare error / 502', 'Confirm the tunnel is Healthy and the worker listens at 127.0.0.1:8766.'],
    ['401 during a real application job', 'Check that both shared secrets match, both clocks are correct, and production cached configuration and queue workers were refreshed.'],
    ['Browser login page instead of worker response', 'Remove interactive login from the API route or configure supported machine-to-machine access.'],
    ['Job remains queued', 'Confirm production has a running queue worker for the mdb queue.'],
    ['Template identity not registered', 'Check the Windows template registry and exact template file hash against production.'],
]);
$body .= $paragraph('Reference documents', 'Heading1');
$sources = [
    'Cloudflare tunnel setup: https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/get-started/create-remote-tunnel/',
    'Cloudflare Quick Tunnel limitations: https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/',
    'Microsoft Access Runtime deployment guidance: https://support.microsoft.com/en-us/access/download-and-install-microsoft-365-access-runtime',
    'Application worker deployment: docs/mdb/WORKER.md',
];
foreach ($sources as $source) {
    $body .= $paragraph($source, 'Small');
}

$ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
$document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="'.$ns.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>'.$body.'<w:sectPr><w:footerReference w:type="default" r:id="rIdFooter"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1000" w:right="1020" w:bottom="1000" w:left="1020" w:header="360" w:footer="420"/></w:sectPr></w:body></w:document>';
$styles = '<?xml version="1.0" encoding="UTF-8"?><w:styles xmlns:w="'.$ns.'"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="100" w:line="260" w:lineRule="auto"/><w:widowControl/></w:pPr></w:pPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>';
foreach ([
    'Title' => '<w:pPr><w:keepNext/><w:spacing w:after="160"/></w:pPr><w:rPr><w:b/><w:color w:val="164D78"/><w:sz w:val="40"/></w:rPr>',
    'Subtitle' => '<w:pPr><w:keepNext/><w:spacing w:after="180"/></w:pPr><w:rPr><w:color w:val="596875"/><w:sz w:val="21"/></w:rPr>',
    'Heading1' => '<w:pPr><w:keepNext/><w:spacing w:before="220" w:after="100"/></w:pPr><w:rPr><w:b/><w:color w:val="164D78"/><w:sz w:val="27"/></w:rPr>',
    'Code' => '<w:pPr><w:spacing w:after="25" w:line="220" w:lineRule="auto"/><w:shd w:fill="F0F3F6"/></w:pPr><w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:sz w:val="17"/></w:rPr>',
    'TableText' => '<w:pPr><w:spacing w:after="30"/></w:pPr><w:rPr><w:sz w:val="20"/></w:rPr>',
    'TableHeader' => '<w:pPr><w:spacing w:after="30"/></w:pPr><w:rPr><w:b/><w:color w:val="164D78"/><w:sz w:val="20"/></w:rPr>',
    'Small' => '<w:rPr><w:sz w:val="18"/></w:rPr>',
] as $name => $format) {
    $styles .= '<w:style w:type="paragraph" w:styleId="'.$name.'"><w:name w:val="'.$name.'"/><w:basedOn w:val="Normal"/>'.$format.'</w:style>';
}
$styles .= '</w:styles>';
$files = [
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/><Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/></Types>',
    '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
    'word/document.xml' => $document,
    'word/styles.xml' => $styles,
    'word/settings.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:settings xmlns:w="'.$ns.'"><w:updateFields w:val="true"/></w:settings>',
    'word/footer1.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:ftr xmlns:w="'.$ns.'"><w:p><w:pPr><w:jc w:val="right"/></w:pPr><w:r><w:rPr><w:color w:val="697683"/><w:sz w:val="18"/></w:rPr><w:t>MDB worker HTTPS setup | Page </w:t></w:r><w:fldSimple w:instr="PAGE"><w:r><w:t>1</w:t></w:r></w:fldSimple></w:p></w:ftr>',
    'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rIdSettings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/><Relationship Id="rIdFooter" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/></Relationships>',
];
$path = __DIR__.'/MDB-Worker-HTTPS-Setup.docx';
$zip = new ZipArchive;
if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Could not create Word document.');
}
foreach ($files as $name => $content) {
    $xml = new DOMDocument;
    if (! $xml->loadXML($content)) {
        throw new RuntimeException('Invalid document XML: '.$name);
    }
    $zip->addFromString($name, $content);
}
$zip->close();
echo 'Saved '.$path.' ('.filesize($path).' bytes)'.PHP_EOL;
