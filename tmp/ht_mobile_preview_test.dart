import 'dart:io';
import 'dart:ui' as ui;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:hazeco_field_survey/field_controller.dart';
import 'package:hazeco_field_survey/local_store.dart';
import 'package:hazeco_field_survey/models.dart';
import 'package:hazeco_field_survey/main.dart';
import 'package:hazeco_field_survey/ht_update_page.dart';
void main() {
 late LocalStore store;
 setUp(() async {
 for (final entry in {'Roboto':'roboto-regular.ttf','MaterialIcons':'materialicons-regular.otf'}.entries) { final loader = FontLoader(entry.key)..addFont(Future.value(ByteData.sublistView(await File('C:/flutter/bin/cache/artifacts/material_fonts/'+entry.value).readAsBytes()))); await loader.load(); }
 sqfliteFfiInit(); store = await LocalStore.open(factory: databaseFactoryFfi, path: inMemoryDatabasePath); });
 tearDown(() async { await store.database.close(); });
 testWidgets('capture mobile HT and LT workflow', (tester) async {
  final controller = FieldController(store)..session = const FieldSession(url: applicationBaseUrl, token: 'preview', userId: 1, name: 'Surveyor Team 01');
  controller.reference = {'teams':[{'id':4,'name':'Survey Team 01','group_identifier':'01'}], 'feeders':[{'id':7,'feeder_code':'000412','feeder_name':'BAGNOTER','team_ids':[4]}]};
  await tester.binding.setSurfaceSize(const Size(430, 932));
  final key = GlobalKey();
  Future<void> capture(String name, Widget child) async {
   if (name == 'mobile-home') {
     await tester.pumpWidget(RepaintBoundary(key:key, child:FieldSurveyApp(controller:controller)));
   } else {
     Navigator.of(tester.element(find.byType(SurveyHome))).push(MaterialPageRoute(builder: (_) => child));
   }
   await tester.pumpAndSettle();
   final boundary = key.currentContext!.findRenderObject() as RenderRepaintBoundary;
   await tester.runAsync(() async {
   final image = await boundary.toImage(pixelRatio:1);
   final bytes = await image.toByteData(format:ui.ImageByteFormat.png);
   await File('../../outputs/ht-network-update/'+name+'.png').writeAsBytes(bytes!.buffer.asUint8List());
   });
  }
  Directory('../../outputs/ht-network-update').createSync(recursive:true);
  await capture('mobile-home', SurveyHome(controller:controller));
  await capture('mobile-ht-update', HtUpdateEditor(controller:controller, feeder:controller.feeders.first, team:controller.teams.first));
  await tester.pumpWidget(const SizedBox.shrink());
  controller.dispose();
 });
}
