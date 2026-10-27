import json, pathlib, collections, math, re, html

ROOT = pathlib.Path(__file__).resolve().parent
dbs = [json.loads(p.read_text(encoding='utf-8-sig')) for p in sorted((ROOT/'raw').glob('*.json'))]
for d in dbs:
    d['tables_by_name'] = {t['name']: t for t in d['tables']}
    d['id'] = d['tables_by_name']['InstPrimaryTransformers']['rows'][0]['UniqueDeviceId']

TABLES = {
'Gnodes':'Additional graphical node information.',
'InstAreaOfInt':'Defined areas of interest for grouping and reporting.',
'InstAreaOfIntPts':'Boundary points for areas of interest.',
'InstBkrRelays':'Installed circuit-breaker relay settings.',
'InstBreakers':'Installed circuit breakers and operating settings.',
'InstCapacitors':'Installed capacitor banks and controls.',
'InstDGens':'Distributed generation installation references.',
'InstDTrans':'Separate distribution-transformer installation records. Empty here; the supplied transformers are recorded in InstPrimaryTransformers.',
'InstFaultEvents':'Fault-event records for studies.',
'InstFeeders':'Feeder identity, source voltage, source impedance and feeder demand settings.',
'InstFeederSet':'Named groups of feeders.',
'InstFeederSetItem':'Members of feeder groups.',
'InstFuses':'Installed fuse devices.',
'InstGenerators':'Installed generator models.',
'InstLargeCust':'Explicit large-customer models.',
'InstMeterPoints':'Meter-point association and demand/reliability settings. Here only SectionId is populated.',
'InstMotors':'Installed motor loads.',
'InstMymAreaOfInt':'Multiyear area-of-interest values.',
'InstMymDGens':'Multiyear distributed-generation values.',
'InstMymLargeCust':'Multiyear large-customer values.',
'InstMymLoads':'Multiyear load values.',
'InstMymMeter':'Multiyear metering values.',
'InstMymProjLoads':'Multiyear project-load values.',
'InstMymSections':'Multiyear section values.',
'InstMymSwDevPos':'Multiyear switching-device positions.',
'InstPrimaryTransformers':'Installed transformer type references, connections, tap controls and reliability settings.',
'InstProjLoads':'Planned or project load records.',
'InstProtectiveDevices':'Protective-device associations.',
'InstReclosers':'Installed reclosers and controls.',
'InstRecRelays':'Recloser relay settings.',
'InstRegulators':'Installed voltage regulators and controls.',
'InstSchematic':'Saved schematic definitions.',
'InstSchematicItem':'Elements of saved schematics.',
'InstSection':'Network branches linking from/to nodes, with phases, conductor references, lengths and load-model settings.',
'InstSectionalizers':'Installed sectionalizers.',
'InstSubstationTransformers':'Separate substation-transformer installations.',
'InstSwitches':'Installed switching devices.',
'InstSwPlan':'Saved switching plans.',
'InstSwPlanItem':'Steps or members of switching plans.',
'InstTccItem':'Items in time-current coordination sets.',
'InstTccSet':'Time-current coordination study sets.',
'InstTextLabels':'Saved map text labels.',
'InstTranBus':'Subtransmission bus records.',
'InstTranLine':'Subtransmission line records.',
'InstTranVertices':'Subtransmission graphical vertices.',
'InstViews':'Saved display views.',
'InstWhatIf':'What-if study definitions.',
'Loads':'Section-associated per-phase customers, kW, kvar, connected/load-basis kVA and kWh.',
'Node':'Network node identifiers and map coordinates.',
'SAI_Control':'Model-wide units, frequency, phase labels, software version, year labels and coordinate-system declaration.'
}

# Meanings below are engineering interpretations of field names unless explicitly sourced.
# Unknown enums and unit scales are deliberately retained, not guessed.
M = {}
def add(field, unit, meaning, note='', basis='Field-name interpretation'):
    M[field] = (unit, meaning, note, basis)

for f, desc in {
'SectionId':'Section identifier. Joins Loads, transformer and meter records to InstSection.',
'FeederId':'Feeder name/identifier. Associates sections with a feeder source.',
'SubstationId':'Name/identifier of the supplying substation. The text is not a separate voltage setting.',
'FromNodeId':'Start node of a section, referencing Node.NodeId.',
'ToNodeId':'End node of a section, referencing Node.NodeId.',
'NodeId':'Identifier of a network connection point, pole or source node.',
'UniqueDeviceId':'Identifier of this particular installed transformer.',
'TransformerType':'Equipment-library key for transformer characteristics. Labels here say 100 KVA or 200 KVA.',
'PhaseConductorId':'Phase-1 (R) conductor equipment-library key.',
'PhaseConductor2Id':'Phase-2 (Y) conductor equipment-library key.',
'PhaseConductor3Id':'Phase-3 (B) conductor equipment-library key.',
'NeutralConductorId':'Neutral-wire equipment-library key.',
'ConfigurationId':'Equipment-library key for conductor geometry/configuration.',
'GrowthCurveId':'Reference to a load-growth curve, used when its enable option is selected.',
'DuctBankId':'Reference to an underground duct-bank type.',
'DuctBankInst':'Identifier of an installed duct bank.',
'LocationLink':'External location/reference link. Asterisk is a stored placeholder, not a physical measurement.',
'AMSLink':'External asset-management-system reference.',
'LockedBy':'User/owner associated with an editing lock.',
'LastSavedBy':'User recorded for the last save.',
'ProjectionFile':'Declared coordinate system used for interpreting map X/Y coordinates.',
'ProjectionWKT':'Coordinate-system definition in Well-Known Text.',
'Product':'Software name and version written into the database.',
'Note_':'Free-text note attached to the record.',
'Description':'Free-text description.',
'Monitor':'Monitoring-related identifier or option. Its exact domain is not supplied.',
'ExposureZone':'Reference to a reliability exposure zone.',
'MitiZone':'Reference to a reliability mitigation zone.',
'GrowthZone':'Reference to a load-growth zone.',
'CostZone':'Reference to a cost zone.',
'DistLoadCustZone':'Customer-zone reference for distributed loads.',
'SpotLoadCustZone':'Customer-zone reference for spot loads.',
'DefDistCustZone':'Default customer-zone reference for distributed loads downstream of this meter.',
'DefSpotCustZone':'Default customer-zone reference for spot loads downstream of this meter.',
}.items(): add(f,'Text / reference',desc)
for f in ['TransformerType','PhaseConductorId','PhaseConductor2Id','PhaseConductor3Id','NeutralConductorId','ConfigurationId']:
    u,desc,_,b=M[f];M[f]=(u,desc,'Library characteristics are not contained in these seven files. Preserve spaces in keys until matching is checked.','ArcFM equipment-ID documentation + field name')

for f in ['ConnectionType','HighSideConnectionCode','LowSideConnectionCode','TertConnectCode']:
    add(f,'Connection code','Winding/source connection code. YG conventionally means grounded wye (star).','Stored connections are model inputs; physical nameplate/vector group is not verified.','Electrical convention + field name')
add('ConnectedPhases','Phase code','Phases connected to the transformer: RYB means all three phases.')
add('SectionPhases','Phase code','Phases present on the section. R/Y/B are phases; N is neutral. Spaces preserve absent phase positions.','RYBN = three phases plus neutral; R  N = R plus neutral; YBN = Y and B plus neutral.')
add('GangOperatingPhase','Phase label','Reference/sensing phase for gang-operated tap control.')
for i,p in enumerate('RYB',1):
    add('Phase'+str(i),'Phase label',f'Name of model phase {i}. Here {p}.')
    for suffix,unit,desc in [
        ('Customers','Customers / allocation units','Allocated customers on this phase. Fractional values represent model allocation, not fractional physical people.'),
        ('Kw','kW','Real-power load input on this phase. Zero is stored in every load row; it is not evidence of zero actual consumption.'),
        ('Kvar','kvar','Reactive-power load input on this phase. All stored values are zero.'),
        ('Kva','kVA','Connected/load-allocation apparent-power basis on this phase. Do not treat its sum as measured coincident peak demand.'),
        ('Kwh','kWh','Energy input on this phase. All values are NULL; no billing/energy period is available.'),
        ('KvaDemand','kVA','Feeder source-demand input for this phase, used for load allocation/calibration when selected.'),
        ('PercentPF','%','Feeder phase power-factor input, expressed in percent. A stored zero is not a usable operating PF.'),
        ('Value','Type-dependent','First phase-specific meter reading. Meaning and unit depend on meter Type, which is NULL here.'),
        ('Value2','Type-dependent','Second phase-specific meter reading. Meaning and unit depend on meter Type, which is NULL here.')]:
        add(f'Phase{i}{suffix}',unit,f'{p} phase: {desc}',basis='ArcFM load attributes + field-name interpretation' if suffix in ['Customers','Kw','Kvar','Kva'] else 'Field-name interpretation')
    add(f'ByPhVoltLevelPh{i}','kV, inferred',f'{p}-phase source-voltage magnitude for the optional by-phase source mode.','UseByPhVoltLevel=0, so this optional mode is disabled. Confirm version-specific voltage convention.')
    add(f'ByPhVoltDegPh{i}','Degrees',f'{p}-phase source-voltage angle for the optional by-phase source mode.','UseByPhVoltLevel=0. Angles 0, -120, +120 represent a balanced phase sequence.')
    add(f'LTCTapPosPh{i}','Tap steps',f'{p}-phase load-tap-changer position. Zero conventionally denotes the neutral tap.','Control behavior depends on the referenced transformer type.')
    add(f'LTCFwdVoltSettingPh{i}','Control volts, inferred',f'{p}-phase forward tap-control voltage target.','122 is a controller/PT-side setting, not 122 kV at the feeder. Confirm the equipment control base.')
    add(f'LTCFwdBWSettingPh{i}','Control volts, inferred',f'{p}-phase forward tap-control bandwidth/deadband.','Used by the tap controller when supported and active.')
    add(f'LTCFwdRDialPhase{i}','Controller R dial units',f'{p}-phase resistive line-drop compensation dial.','Exact unit scale depends on controller/equipment definitions.')
    add(f'LTCFwdXDialPhase{i}','Controller X dial units',f'{p}-phase reactive line-drop compensation dial.','Exact unit scale depends on controller/equipment definitions.')
    add(f'TertIncidentSectId{i}','Section reference',f'Tertiary-winding incident section reference {i}.','Asterisk is stored in all files; no actual tertiary connection is identified.')

for f,unit,desc,note in [
('NominalKvll','kV line-to-line','Nominal feeder line-to-line voltage.','11 is the feeder voltage; the 132KV text in SubstationId is a name.'),
('BusVoltageLevel','kV, inferred','Source-bus operating voltage setting.','11.37 versus nominal 11 means a source setting about 3.36% above nominal; not a downstream solved voltage.'),
('PosSequenceResistance','ohm, inferred','Positive-sequence source resistance for fault/sequence-network calculations.','Zero input provides no nonzero source resistance.'),
('PosSequenceReactance','ohm, inferred','Positive-sequence source reactance for fault/sequence-network calculations.','Zero input provides no nonzero source reactance.'),
('ZeroSequenceResistance','ohm, inferred','Zero-sequence source resistance, particularly relevant to ground faults.','All stored values are zero.'),
('ZeroSequenceReactance','ohm, inferred','Zero-sequence source reactance, particularly relevant to ground faults.','All stored values are zero.'),
('LineToGroundFaultResistance','ohm, inferred','Resistance assumed in the line-to-ground fault path.','Zero models no added fault resistance if this input is used.'),
('FeederMultiplier','Multiplier','Scales feeder load under the selected analysis settings.','1 means no additional multiplier.'),
('AmpRating','A','Normal current rating/override value.','On sections, UseSectAmpRating=0 disables the stored 100 A instance override; the equipment-library rating may apply.'),
('EmerAmpRating','A','Emergency feeder current rating.','Stored zero is not a meaningful confirmed equipment capability.'),
('ContinuousCurrentRating','A','Meter-point continuous current rating.','NULL in all files.'),
('EmergencyCurrentRating','A','Meter-point emergency current rating.','NULL in all files.'),
('TapSetting','Multiplier, inferred','Transformer fixed tap-ratio setting.','1 suggests nominal ratio. Distinct from LTC tap step 0; exact convention needs the transformer library.'),
('HighSideGroundingResistance','ohm, inferred','Transformer high-side neutral grounding resistance.','Zero means no added grounding resistance if used.'),
('LowSideGroundingResistance','ohm, inferred','Transformer low-side neutral grounding resistance.','Zero means no added grounding resistance if used.'),
('HighSideGroundingReactance','ohm, inferred','Transformer high-side neutral grounding reactance.','All stored values are zero.'),
('LowSideGroundingReactance','ohm, inferred','Transformer low-side neutral grounding reactance.','All stored values are zero.'),
('SustFailuresPrYear','Failures/year','Assumed sustained-failure rate for reliability calculations.','A zero setting is an input/default, not observed proof of no failures.'),
('TempFailuresPrYear','Failures/year','Assumed temporary-failure rate for reliability calculations.','A zero setting is an input/default, not observed proof of no failures.'),
('SustFailRepairTime_Hr','Hours','Assumed duration to repair a sustained failure.','Transformer records contain 6; feeder records contain 0.'),
('AvgCrewOperResetTime_Hr','Hours','Average crew operation/reset time for reliability calculations.','1 hour is stored.'),
('SustFailRepairCost_MU','Model monetary units','Assumed repair cost for a sustained transformer failure.','5000 is stored. Currency is not specified, so it cannot be labelled PKR.'),
('LTCTapLimHigh','Tap steps','Upper permitted LTC tap step when tap limits are enabled.','6 is stored, but LTCTapLimActive=0.'),
('LTCTapLimLow','Tap steps','Lower permitted LTC tap step when tap limits are enabled.','-4 is stored, but LTCTapLimActive=0.'),
('FirstHouseHighVoltage','Control voltage units, inferred','Upper first-house voltage limit used by the associated control option.','124 is stored; FirstHouseActive=0 disables the option.'),
('FirstHouseLowVoltage','Control voltage units, inferred','Lower first-house voltage limit used by the associated control option.','124 is stored; FirstHouseActive=0 disables the option.'),
('PercentImpedance','%','Transformer instance impedance override.','UseInstanceImpedance=0 disables this override; zero here does not establish zero actual transformer impedance.'),
('PercentResistance','%','Transformer instance resistance override.','UseInstanceImpedance=0 disables this override.'),
('SpecNomKv','kV','Specified nominal-voltage field for transformer voltage selection.','4.16 is stored. NomKvMode=C is not decoded, so do not infer the actual primary or secondary rating from 4.16 alone.'),
('PhaseToPhaseSpacing_MUL','Model metric spacing unit; confirm','Equivalent spacing between phase conductors, used if equivalent-spacing mode is selected.','121.9 is stored; UseEquivSpacing=0. Do not assume metres. Metric geometry scales can differ from section-length units.'),
('PhaseToNeutralSpacing_MUL','Model metric spacing unit; confirm','Equivalent spacing between phases and neutral.','91.4 is stored; UseEquivSpacing=0. Exact scale needs SynerGEE 5 documentation/settings.'),
('AveHeightAboveGround_MUL','Model metric geometry unit; confirm','Equivalent average conductor height above ground.','9.1 is stored; UseEquivSpacing=0. Referenced ConfigurationId may supply the active geometry.'),
('SectionLength_MUL','m, supported by coordinates','Section electrical/model length.','LengthUnits=Metric. All 130 secondary-distribution lengths equal straight-line UTM X/Y distances. Includes 1-unit connector sections in overall totals.'),
('PercentLoadGrowthRate','% per year, inferred','Section annual load-growth assumption for projection studies.','2 is stored; application depends on study year and growth settings.'),
('ElbowOrBayAmpRating','A','Current rating for section-end elbow/bay arrangements.','100 is stored; end status codes are N and their exact enum is unverified.'),
('DuctBankCircuit','Circuit number','Circuit position/index within a duct bank.','1 is stored, but duct-bank IDs are empty and duct-bank options are disabled.'),
('EnergizeYear','Study-year index, inferred','Study-year value controlling when equipment enters service.','0 is a model value, not a calendar year or an installation date.'),
('RetireYear','Study-year index, inferred','Study-year value controlling when equipment retires.','100 is a distant/default study horizon value, not calendar year 100.'),
('ExpFactPermFail','Multiplier','Section exposure factor applied to permanent-failure rates.','1 means no additional scaling.'),
('ExpFactTempFail','Multiplier','Section exposure factor applied to temporary-failure rates.','1 means no additional scaling.'),
('ExpFactRepair','Multiplier','Section factor applied to repair-related reliability inputs.','1 means no additional scaling.'),
('CapacityFactor','Multiplier','Load capacity/allocation factor.','1 is stored. Exact use depends on the selected load-allocation method.'),
('CalibSAIFI','Interruptions/customer/year','Meter-point SAIFI target for reliability calibration.','NULL. SAIFI is sustained interruption frequency.'),
('CalibSAIDI_Hr','Hours/customer/year','Meter-point SAIDI target for reliability calibration.','NULL. SAIDI is sustained interruption duration.'),
('CalibMAIFI','Interruptions/customer/year','Meter-point MAIFI target for reliability calibration.','NULL. MAIFI is momentary interruption frequency.'),
('VoltageFactor','Version-specific voltage scale','Global voltage scaling/reference field.','11 is stored. Exact behavior is not established from the supplied databases; nominal feeder voltage is separately recorded.'),
('Frequency','Hz','Electrical model frequency, affecting frequency-dependent network quantities.','60 is stored in every file. Review against the intended 50 Hz Pakistan network.'),
('PTSecondary','V, inferred','Potential-transformer/controller secondary reference voltage.','120 is stored. This is a control reference, not the LV consumer supply voltage.'),
('LengthUnits','Unit-system code','Selects the model length/geometry unit system.','Metric in all files; this alone does not establish one common unit for every _MUL geometry field.'),
('X','Projected map units; metre if declared CRS correct','Node easting/map X coordinate.','Declared CRS is WGS 1984 UTM Zone 43N; coordinates are not longitude degrees.'),
('Y','Projected map units; metre if declared CRS correct','Node northing/map Y coordinate.','Declared CRS is WGS 1984 UTM Zone 43N; coordinates are not latitude degrees.'),
('LastSavedDate','Date/time','Date recorded for the last save.','NULL in all files.'),
('BuiltDate','Date/time','Recorded model/equipment build date.','NULL in all files.')]: add(f,unit,desc,note)

for f,desc in {
'DefaultSelect':'Select feeder by default when opening/loading the model.',
'UseByPhVoltLevel':'Enable separate source voltage magnitudes/angles by phase.',
'UseGrowthCurve':'Use the referenced growth curve rather than the applicable alternative growth method.',
'MeterIsOpen':'Represent the meter-point connection as open.',
'UseDemandAsLoad':'Use meter demand as the downstream load basis.',
'Enabled':'Enable the meter point.',
'EnableReliCalibration':'Enable reliability calibration from meter-point targets.',
'NearFromNode':'Locate the device near the section FromNode end; 0 indicates the other end under the conventional flag interpretation.',
'HighSideNearFromNode':'Orient the transformer high-voltage side toward the section FromNode end.',
'OverriddenUpstream':'Flag demand treatment as overridden by an upstream point.',
'UseDefDistCustZone':'Apply a default distributed-load customer zone.',
'UseDefSpotCustZone':'Apply a default spot-load customer zone.',
'IsColorBy':'Enable the record-specific display color.',
'LockFedLoad':'Lock the meter-fed load during allocation; exact solver behavior needs version documentation.',
'LTCIsGangOperated':'Operate phase tap changers together as a gang.',
'LTCInManualMode':'Place the load tap changer in manual mode.',
'LTCTapLimActive':'Enable the stored upper/lower tap limits.',
'FirstHouseActive':'Enable first-house voltage-limit control.',
'NetProtEnabled':'Enable transformer network protection.',
'NetProtIsAuto':'Use automatic network-protector operation.',
'NetProtIsOpen':'Set the network protector open.',
'UseInstanceImpedance':'Use record-specific transformer impedance instead of the equipment-library value.',
'LoadConnectionPhaseGnd':'Connect section loads phase-to-ground/neutral under the model flag convention.',
'UseEquivSpacing':'Use the equivalent spacing/height fields rather than the applicable configuration model.',
'IdenticalPhaseConductors':'Use the identical-conductor option for all phases. Here 0: individual phase references are retained.',
'IsContingency':'Designate the section for contingency-related modeling.',
'UseSectAmpRating':'Use the section-specific normal ampere-rating override.',
'UseDistCustZone':'Apply a distributed-load customer zone to the section.',
'UseSpotCustZone':'Apply a spot-load customer zone to the section.',
'UseCustClassDistLoadIzpq':'Use customer-class voltage-dependence settings for distributed load.',
'UseCustClassSpotLoadIzpq':'Use customer-class voltage-dependence settings for spot load.',
'UseDuctBankAmpRating':'Use the duct-bank-derived current rating.',
'UseDuctBankConfiguration':'Use the duct-bank conductor configuration.',
'SpotLoadIsOff':'Turn the section spot load off.',
'NeutIsGrounded':'Mark the neutral as grounded.',
'HideMapResults':'Hide results for this section on the map.',
'IsSpotLoad':'Distinguish a lumped spot load from a distributed section load. Here 0 indicates distributed load.',
'IsPadMountGear':'Identify the node as pad-mounted switching gear.',
'VoltageInPercent':'Display/interpret the applicable voltage quantities as percent under the model setting.',
}.items(): add(f,'Flag: 0/1',desc,'Conventionally 0=no/off and 1=yes/on. NULL means not entered, not a confirmed off state.','Flag-name interpretation')

for f,desc,note in [
('MappingLink','Mapping/link option code.','0 is stored; exact enumerated meaning is not supplied.'),
('PositionCode','Section conductor-position/configuration code.','0 is stored; exact enumerated meaning is not supplied.'),
('LoadCategory','Section load category code.','0 is stored; category mapping is not supplied.'),
('FromElbowOrBayStatus','From-end elbow/bay status.','N is stored; exact version-specific domain is not verified.'),
('ToElbowOrBayStatus','To-end elbow/bay status.','N is stored; exact version-specific domain is not verified.'),
('NomKvMode','Transformer nominal-voltage selection mode.','C is stored; do not assume what C selects without SynerGEE 5 documentation/equipment settings.'),
('LocationToModelSpotLoads','Placement code for a lumped spot load along the section.','C is stored; likely center, but enum is unverified. IsSpotLoad=0 makes spot-load placement inactive here.'),
('Type','Meter measurement type determining the interpretation of PhaseNValue/Value2.','NULL in all records, so meter reading units cannot be decoded.')]: add(f,'Version-specific code',desc,note,'Unverified enum')
for f in ['FeederRGBColor','RGBColor']:
    add(f,'RGB text','Display color as red, green, blue components.','0,255,0 is green for the feeder. It does not change electrical behavior.')
for kind in ['Dist','Spot']:
    for model in ['Impedance','Current']:
        f=f'Percent{kind}LoadConst{model}'
        add(f,'%',f'Constant-{model.lower()} share of the {"distributed" if kind=="Dist" else "spot"} load voltage-dependence (ZIP) model.',
            'Distributed: Z=40%, I=10%, remaining P=50%. Spot: Z=40%, I=30%, remaining P=30%. Customer-class overrides are off.')
for f in [c['name'] for c in dbs[0]['tables_by_name']['SAI_Control']['columns'] if c['name'].startswith('MYM_')]:
    add(f,'Year label (text)','Label for a multiyear model scenario slot.','2015–2025 labels are stored. Only InstMymDGens has multiyear records, at YearNumber=0. Labels do not prove the survey/approval date.')
for f,unit,desc,note in [
('DGenDesc','Text / reference','Distributed-generation description/identifier.','Four records have PVGEN-prefixed descriptions; generator type itself is NULL.'),
('DGenType','Version-specific code','Distributed-generation model/type selection.','NULL in all four generation records; exact generator technology/control type is not established.'),
('DGenCustClass','Class reference','Customer/generation class for the distributed-generation model.','NULL in all four records.'),
('DGenLocation','Version-specific location','Generation location or location/profile reference.','NULL. Exact domain is not supplied.'),
('DGenTilt','Degrees, inferred','Generation/PV tilt parameter, inferred from the field name.','NULL; no actual tilt value is entered.'),
('DGenCapFactor','Factor; scale unverified','Distributed-generation capacity-factor parameter.','NULL. Separate year-record CapacityFactor=1 values are present.'),
('DGenOutputPct','%','Distributed-generation output percentage setting.','100 is entered. This is a model setting, not a measured export value.'),
('DGenVoltSet','Control voltage units, inferred','Distributed-generation voltage target/control reference.','120 is stored. Exact control base and whether it is used need the generation model definition.'),
('DGenStatus','Version-specific status','Distributed-generation service/operating status code.','NULL in every record. Do not infer that these generators are enabled or exporting.'),
('YearNumber','Study-year index','Year slot for this multiyear distributed-generation record.','All four records use 0, likely the base-year slot; the control base-year label is 2015. This is not evidence of installation date.')]: add(f,unit,desc,note)

def meaning_for(table, field):
    if table == 'InstMymDGens' and re.fullmatch(r'Phase[123]Kw', field):
        return ('kW',f'{"RYB"[int(field[5])-1]}-phase generation real-power input for this study-year record.','Generation inputs are separate from Loads.PhaseNKw=0. Nameplate versus scheduled-output interpretation needs generation/study settings.','Field-name interpretation')
    if table == 'InstMymDGens' and re.fullmatch(r'Phase[123]Kvar', field):
        return ('kvar',f'{"RYB"[int(field[5])-1]}-phase generation reactive-power input for this study-year record.','All four generation records contain zero kvar.','Field-name interpretation')
    if table == 'InstMymDGens' and field == 'CapacityFactor':
        return ('Multiplier','Year-specific generation capacity factor/scaling input.','1 is stored. Application depends on generation study settings.','Field-name interpretation')
    return M[field]

def display(v):
    if v is None: return '<NULL>'
    if isinstance(v,str): return json.dumps(v,ensure_ascii=False)
    return str(v)
def state(v):
    if v is None:return 'NULL / not entered'
    if isinstance(v,str):
        if v=='':return 'Empty text'
        if not v.strip():return f'Whitespace-only text ({len(v)} space)'
        if v.startswith(' ') or v.endswith(' '):return 'Text with edge spaces'
        return 'Text'
    return 'Numeric zero' if v==0 else 'Numeric'

fields=[]; entries=[]; settings=[]; loads=[]; sections=[]; nodes=[]; summaries=[]; generators=[]
for t in dbs[0]['tables']:
    if not any(d['tables_by_name'][t['name']]['rows'] for d in dbs):continue
    for c in t['columns']:
        f=c['name']
        assert f in M, (t['name'],f)
        unit,meaning,note,basis=meaning_for(t['name'],f)
        vals=[r[f] for d in dbs for r in d['tables_by_name'][t['name']]['rows']]
        counter=collections.Counter(display(v) for v in vals)
        examples='; '.join(f'{v} (n={n})' for v,n in counter.most_common(7))
        if len(counter)>7:examples+=f'; plus {len(counter)-7} other distinct values; see All entries'
        fields.append([t['name'],f,unit,meaning,note,basis,examples])
        if t['name'] in ['InstFeeders','InstMeterPoints','InstPrimaryTransformers','SAI_Control']:
            settings.append([t['name'],f,unit,meaning]+[d['tables_by_name'][t['name']]['rows'][0][f] if d['tables_by_name'][t['name']]['rows'][0][f] is not None else '<NULL>' for d in dbs]+[note])

for d in dbs:
    ts=d['tables_by_name'];lr=ts['Loads']['rows'];ss=ts['InstSection']['rows']; nn=ts['Node']['rows'];tr=ts['InstPrimaryTransformers']['rows'][0];fe=ts['InstFeeders']['rows'][0]
    phase_customers=[sum(r[f'Phase{i}Customers'] or 0 for r in lr) for i in range(1,4)]
    phase_kva=[sum(r[f'Phase{i}Kva'] or 0 for r in lr) for i in range(1,4)]
    summaries.append({'file':d['file'],'id':d['id'],'feeder':fe['FeederId'],'type':tr['TransformerType'],'rows':sum(t['count'] for t in d['tables']),'sections':len(ss),'loads':len(lr),'nodes':len(nn),'customers':sum(phase_customers),'kva':sum(phase_kva),'length':sum(r['SectionLength_MUL'] for r in ss),'phase_customers':phase_customers,'phase_kva':phase_kva,'hash':d['sha256']})
    for t in d['tables']:
        for idx,r in enumerate(t['rows'],1):
            rid=r.get('SectionId',r.get('NodeId',r.get('FeederId','Control record')))
            for c in t['columns']:
                f=c['name'];v=r[f];unit,meaning,note,basis=meaning_for(t['name'],f)
                raw='<NULL>' if v is None else ('""' if v=='' else v)
                entries.append([d['file'],t['name'],str(rid),idx,f,raw,state(v),unit,meaning,note])
    for r in lr:
        vals=[r[f'Phase{i}{suffix}'] if r[f'Phase{i}{suffix}'] is not None else '<NULL>' for suffix in ['Customers','Kva','Kw','Kvar','Kwh'] for i in range(1,4)]
        loads.append([d['id'],r['SectionId'],r['IsSpotLoad']]+vals+[r['CapacityFactor'],r['LocationToModelSpotLoads']])
    for r in ss:
        sections.append([d['id'],r['SectionId'],r['FromNodeId'],r['ToNodeId'],r['SectionPhases'],r['Description'],r['SectionLength_MUL'],r['PhaseConductorId'] or '""',r['PhaseConductor2Id'],r['PhaseConductor3Id'] or '""',r['NeutralConductorId'] or '""',r['ConfigurationId']])
    used={r[k] for r in ss for k in ['FromNodeId','ToNodeId']}
    for r in nn:nodes.append([d['id'],r['NodeId'],r['X'],r['Y'],'Used by section' if r['NodeId'] in used else 'No incident section',r['IsPadMountGear']])
    for r in ts['InstDGens']['rows']:
        yr=next(y for y in ts['InstMymDGens']['rows'] if y['SectionId']==r['SectionId'])
        generators.append([d['id'],r['SectionId'],r['DGenDesc'],yr['YearNumber'],yr['Phase1Kw'],yr['Phase2Kw'],yr['Phase3Kw'],sum(yr[f'Phase{i}Kw'] for i in range(1,4)),yr['Phase1Kvar'],yr['Phase2Kvar'],yr['Phase3Kvar'],yr['CapacityFactor'],r['DGenOutputPct'],r['DGenVoltSet'],'<NULL>','<NULL>'])
    summaries[-1]['dg_count']=len(ts['InstDGens']['rows'])
    summaries[-1]['dg_kw']=sum(y[f'Phase{i}Kw'] for y in ts['InstMymDGens']['rows'] for i in range(1,4))

inventory=[[t['name'],TABLES[t['name']]]+[d['tables_by_name'][t['name']]['count'] for d in dbs] for t in dbs[0]['tables']]
assert len(entries)==13180 and len(fields)==257 and sum(s['rows'] for s in summaries)==475
payload={'summaries':summaries,'fields':fields,'entries':entries,'settings':settings,'loads':loads,'sections':sections,'nodes':nodes,'inventory':inventory,'generators':generators}
(ROOT/'analysis_data.json').write_text(json.dumps(payload,ensure_ascii=False),encoding='utf-8')

SOURCES=[
('S1','ArcFM: Supported Attributes for SynerGEE Loads','https://resources.arcfmsolution.com/10.2.1c/DesktopSDK/ESLoads.html','Documents per-phase customers, power and connected kVA for load allocation. Its XML loadtype enum differs from this MDB IsSpotLoad flag.'),
('S2','ArcFM: Modify Equipment ID Format – SynerGEE','https://resources.arcfmsolution.com/10.8.1/DesktopSDK/topic104.html','Explains equipment-type and phase-conductor keys and the separate equipment database.'),
('S3','NEPRA Distribution Code 2005, SPR 2(b)','https://nepra.org.pk/Legislation/6-Codes/6.1%20Distribution%20Code%20%202005/Distribution%20Code%202005.pdf','Supports the 50 Hz nominal frequency review for the intended Pakistan network.'),
('S4','DNV: Synergi Electric technical documentation','https://mysoftware.dnv.com/knowledge-centre/synergi-electric/tech-doc/','Documentation catalogue. Exact SynerGEE 5 enum and unit definitions were not established from the publicly accessible catalogue.')]

def esc(x): return html.escape(str(x))
def table(head,rows,cls=''):
    return '<div class="scroll"><table class="'+cls+'"><thead><tr>'+''.join('<th>'+esc(x)+'</th>' for x in head)+'</tr></thead><tbody>'+''.join('<tr>'+''.join('<td>'+esc(x)+'</td>' for x in row)+'</tr>' for row in rows)+'</tbody></table></div>'
parts=['''<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>SynerGEE MDB values and purpose</title><style>
body{font:16px/1.6 Arial,sans-serif;color:#243348;background:#eef2f6;margin:0}main{max-width:1280px;margin:30px auto;background:white;padding:38px 48px}h1{font-size:32px;line-height:1.2;color:#183554}h2{font-size:24px;margin-top:42px;color:#183554;border-bottom:2px solid #dbe4ee;padding-bottom:6px}h3{font-size:19px}p{max-width:1100px}.meta{color:#64748b;font-size:14px}nav{display:flex;gap:18px;flex-wrap:wrap;border-block:1px solid #dbe4ee;padding:12px 0}a{color:#1d5c9d}.callout{border-left:4px solid #a26816;background:#fff7e8;padding:14px 20px;margin:20px 0}.scroll{overflow:auto}table{border-collapse:collapse;width:100%;font-size:14px;margin:16px 0}th{text-align:left;background:#203e60;color:white;position:sticky;top:0}td,th{padding:10px 12px;vertical-align:top;border-bottom:1px solid #dbe4ee}tbody tr:nth-child(even){background:#f5f8fc}code{background:#edf2f7;padding:2px 5px;white-space:pre-wrap}.dictionary{font-size:13px}.dictionary td:nth-child(2){font-family:Consolas,monospace;min-width:170px}.dictionary td:nth-child(4){min-width:240px}input{font:inherit;width:95%;padding:12px;border:1px solid #b8c8d9;border-radius:4px}.small{font-size:13px}.tag{background:#e9eff6;padding:2px 6px}li{margin:8px 0}@media print{body{background:white}main{margin:0;padding:0;max-width:none}nav,input{display:none}.scroll{overflow:visible}th{position:static}tr{break-inside:avoid}h2{break-after:avoid}table{font-size:10px}.dictionary{font-size:9px}a{color:inherit}}
</style><main><p class="meta">Seven supplied MDB files · Analysis date: 27 October 2026 · Read-only extraction</p><h1>What is entered in the SynerGEE files, and why?</h1><nav><a href="#summary">File summary</a><a href="#values">Main values</a><a href="#loads">Load interpretation</a><a href="#review">Items to review</a><a href="#dictionary">All field meanings</a><a href="#sources">Sources and method</a></nav>''']
parts.append('<p>These files contain <strong>electrical network model inputs</strong>: feeder source settings, transformer references, network sections, per-phase load allocations and map coordinates. They are not tables of solved voltage drop, losses, current or measured transformer utilization.</p>')
parts.append('<p>The complete Excel reference contains <strong>all 13,180 stored field values</strong> from <strong>475 records</strong>, with source file, table, record identifier, field name, units, short meaning and interpretation notes. The dictionary below explains all <strong>257 table-specific fields across nine populated tables</strong>. Each database has the same 50-table schema: seven tables are populated in every file; three Jodh Pur files also have distributed-generation and year-specific generation records. Forty-one tables are empty in all seven files. Empty tables mean no records were supplied there; they do not prove those devices are absent in the physical network.</p>')
parts.append('<p><a href="Synergee_MDB_Entry_Reference.xlsx">Open the complete Excel entry reference</a>. Use <strong>All entries</strong> to find an exact record. Use <strong>Settings</strong> to compare feeder, transformer, meter and model-wide values across the seven files.</p>')
parts.append('<h2 id="summary">1. File-by-file summary</h2>')
parts.append(table(['Feeder','Transformer ID','Type label','Sections','Load rows','Nodes','Customer allocation sum','Entered kVA sum','Model length (m)'],[[s['feeder'],s['id'],s['type'],s['sections'],s['loads'],s['nodes'],f"{s['customers']:.0f}",f"{s['kva']:,.1f}",f"{s['length']:,.2f}"] for s in summaries]))
parts.append('<p class="small">Type labels are equipment-library names, not independently checked nameplate ratings. Customer and kVA sums add the entered R/Y/B phase values; NULL values are excluded. Every phase marked present has a numeric customer and kVA entry. kVA totals are connected/allocation inputs, not measured coincident demand. Length totals include two 1 m transformer/secondary connector sections in each file.</p>')
parts.append('<h2 id="values">2. Main entries and their purpose</h2>')
main_values=[
('SAI_Control.Product','SynerGEE Electric 5.0.0.324','Software version stored in every database.'),
('InstFeeders.SubstationId','132KV M.Pur','Supplying-substation identifier. The 132KV text does not set the feeder voltage.'),
('InstFeeders.NominalKvll','11 kV','Nominal line-to-line feeder voltage.'),
('InstFeeders.BusVoltageLevel','11.37','Source operating-voltage setting, interpreted as kV. About 3.36% above 11 kV.'),
('SAI_Control.Frequency','60 Hz','Network model frequency. Review for intended Pakistan 50 Hz operation [S3].'),
('SAI_Control.LengthUnits','Metric','Model length/geometry unit system. Section lengths match metre-based coordinate distances; geometry spacing scales still need confirmation.'),
('SAI_Control.Phase1 / Phase2 / Phase3','R / Y / B','Associates Phase1/2/3 fields with red, yellow and blue phases.'),
('SAI_Control.ProjectionFile','WGS 1984 UTM Zone 43N','Declared map coordinate system. X/Y are projected coordinates, not latitude/longitude.'),
('SAI_Control.PTSecondary','120','PT/controller reference, interpreted as volts. It is not a consumer LV supply-voltage entry.'),
('InstFeeders.ConnectionType','YG','Grounded-wye source connection under the usual electrical convention.'),
('InstFeeders.FeederMultiplier','1','No additional feeder load scaling by this multiplier.'),
('InstFeeders.UseByPhVoltLevel','0','Optional by-phase source mode is off. Stored phase values are 11, 11, 11 with angles 0°, -120°, +120°.'),
('InstFeeders.Phase1/2/3KvaDemand and PercentPF','All 0','No nonzero feeder demand or usable source PF has been entered in these fields.'),
('InstPrimaryTransformers.TransformerType','100 KVA or 200 KVA','Transformer equipment-library key. The library is needed for exact voltage ratio, losses and impedance [S2].'),
('Transformer HighSideConnectionCode / LowSideConnectionCode','YG / YG','Stored model connections on both sides. Compare with the equipment/nameplate vector group if validating the model.'),
('Transformer TapSetting / LTCTapPosPh1/2/3','1 / 0,0,0','Fixed ratio setting and separate tap-controller step positions. These are different settings.'),
('Transformer LTCFwdVoltSettingPh1/2/3','122, 122, 122','Controller voltage target; likely PT/control-side volts. Not feeder kV.'),
('Transformer LTCFwdBWSettingPh1/2/3','0.75, 0.75, 0.75','Tap-controller bandwidth; exact base requires the equipment/control definition.'),
('Transformer LTCTapLimActive / High / Low','0 / 6 / -4','Tap bounds are stored, but the limit-enable flag is off.'),
('Transformer UseInstanceImpedance / PercentImpedance / PercentResistance','0 / 0 / 0','Record-specific impedance override is off. Zero fields do not establish zero actual transformer impedance.'),
('Transformer NomKvMode / SpecNomKv','C / 4.16','Voltage selection mode is unresolved. Do not identify the actual transformer primary/secondary rating from 4.16 alone.'),
('Transformer SustFailRepairTime_Hr / SustFailRepairCost_MU','6 / 5000','Reliability repair duration and modeled cost. The cost currency is not defined.'),
('InstSection.PhaseConductorId / PhaseConductor2Id / PhaseConductor3Id','ANT, WASP, DOG, GNAT, ABC 50 MM2; some blanks','Phase-specific conductor library references. Conductor resistance/reactance/ampacity cannot be reconstructed from labels alone [S2].'),
('InstSection.ConfigurationId','12.5/7.2 kV cross arm C2-2','Configuration-library name. Text in a configuration name does not establish actual operating voltage.'),
('InstSection.UseEquivSpacing','0','Equivalent-spacing option is off; check the referenced configuration for active geometry.'),
('InstSection.PhaseToPhaseSpacing_MUL / PhaseToNeutralSpacing_MUL / AveHeightAboveGround_MUL','121.9 / 91.4 / 9.1','Stored geometry fields. Exact spacing/height unit scales are unresolved; do not interpret all three as metres.'),
('InstSection.UseSectAmpRating / AmpRating','0 / 100','Section 100 A instance override is disabled. It is not a confirmed active rating.'),
('Distributed ZIP load percentages','40% impedance, 10% current, 50% power remainder','Voltage dependence of distributed loads: how load changes as voltage changes.'),
('Spot ZIP load percentages','40% impedance, 30% current, 30% power remainder','Voltage dependence for spot loads. All supplied Loads rows have IsSpotLoad=0.'),
('InstSection.PercentLoadGrowthRate','2','Growth assumption, interpreted as percent per year when used in a projection study.'),
('InstMeterPoints.SectionId','Transformer secondary section ID','A meter association exists, but every other meter field is NULL. No meter readings are supplied.'),
('SAI_Control.MYM_YearDesc_*','2015 through 2025','Multiyear slot labels. Only generation has year-specific records, at YearNumber=0. These are not confirmed survey dates.')]
parts.append(table(['Entry','Stored value(s)','Purpose / interpretation'],main_values))
parts.append('<h2 id="loads">3. How to understand the load entries</h2>')
parts.append('<p><strong>kW</strong> is real power, <strong>kvar</strong> is reactive power, <strong>kVA</strong> is apparent power or a connected-capacity basis, and <strong>kWh</strong> is energy over a period. They serve different purposes and cannot be substituted for one another. ArcFM documentation specifically describes connected kVA as a basis from which SynerGEE can allocate feeder loads [S1].</p>')
parts.append('<p>Across all 137 load records, all 411 kW phase cells and all 411 kvar phase cells are numeric zero. All 411 kWh phase cells are NULL. Nonzero quantities are entered in the customer and kVA fields. The selected allocation method, customer-class definitions, equipment database and study settings are therefore needed to establish how these inputs become an actual solved load. No peak demand, losses, power factor, voltage drop or transformer loading percentage has been inferred from these entries.</p>')
example=dbs[0]['tables_by_name']['Loads']['rows'][1]
parts.append('<h3>Worked example: section '+esc(example['SectionId'])+'</h3>')
parts.append(table(['Field group','R phase','Y phase','B phase','Meaning'],[
['PhaseNCustomers',example['Phase1Customers'],example['Phase2Customers'],example['Phase3Customers'],'6.3333 on each phase totals 19 allocated customers.'],
['PhaseNKva',19,19,19,'19 kVA per phase totals 57 kVA of entered connected/load basis.'],
['PhaseNKw',0,0,0,'No nonzero real-power input entered.'],
['PhaseNKvar',0,0,0,'No nonzero reactive-power input entered.'],
['PhaseNKwh','NULL','NULL','NULL','No energy/billing input entered.']]))
parts.append('<p>This example shows why a value must be read together with its field and phase. The stored number 19 in each kVA field is <strong>per phase</strong>, while 19 is also the sum of the three allocated customer fields. It does not mean the same thing in both places.</p>')
parts.append('<h3>Separate distributed-generation entries</h3><p>Four PVGEN-labelled records occur in three Jodh Pur files. Their corresponding InstMymDGens records contain the following real-power inputs. All generation kvar fields are 0, YearNumber is 0, CapacityFactor is 1, DGenOutputPct is 100 and DGenVoltSet is 120. DGenType and DGenStatus are NULL, so these records do not establish the generator technology, enabled state or measured export. They do not change the observation that all customer-load kW fields are zero.</p>')
parts.append(table(['Transformer','Section ID','Generation description','R input (kW)','Y input (kW)','B input (kW)','Three-phase sum (kW)'],[[g[0],g[1],g[2],f'{g[4]:.6f}',f'{g[5]:.6f}',f'{g[6]:.6f}',f'{g[7]:.2f}'] for g in generators]))
parts.append('<h3>Flags, blanks and codes</h3>'+table(['Stored form','How to read it'],[
['0','A real numeric zero. For named enable flags it conventionally means off/no. A zero measurement input is not a measured physical zero.'],
['1','For named flags, conventionally on/yes. For multipliers, 1 means no additional scaling. Meaning follows the field name.'],
['NULL','No value was entered. It is different from zero and from an empty string.'],
['""','An entered empty text string.'],
['" " or " ANT"','Whitespace is part of the stored text. Phase2 conductor keys have a leading space in every section.'],
['*','Placeholder in reference/link fields. Not a number or verified linked object.'],
['RYBN / R  N /   BN','Phases present: R/Y/B are electrical phases and N is neutral. Interior spaces can mark missing phases.'],
['C, N, PositionCode=0, LoadCategory=0','Version-specific enums retained as stored. Exact meanings are not asserted without the matching domain definitions.']]))
parts.append('<h2 id="review">4. Findings that matter when using the model</h2><ol>')
review=[
'<strong>Frequency:</strong> all seven files contain Frequency=60 Hz. The cited Pakistan distribution code uses a 50 Hz nominal basis [S3]. Review the intended model frequency before relying on results; no source values have been changed.',
'<strong>Connected kVA is not peak demand:</strong> for example, T-3030216724 has a 200 KVA type label and an entered kVA sum of 518. Dividing them does not establish 259% operating loading. Load allocation/diversity and solved demand are required.',
'<strong>Equipment library missing:</strong> transformer and conductor names are references. Exact transformer voltage ratio, impedance, winding vector group, conductor size/material, ampacity and electrical constants are not fully specified by these network files [S2].',
'<strong>Meter records are placeholders:</strong> each file has one InstMeterPoints row, but only SectionId is populated. All 33 other fields are NULL; there are no supplied meter readings or meter demand targets.',
'<strong>Source fault impedances are zero:</strong> all four source sequence-impedance fields are zero. This is not a verified utility short-circuit equivalent and limits interpretation of fault-study inputs.',
'<strong>Physical transformer voltages remain unresolved:</strong> SpecNomKv=4.16 occurs in every transformer record, together with NomKvMode=C. Its active meaning is unverified. PTSecondary=120 and LTC targets of 122 are control-related values, not proof of LV system voltage.',
'<strong>Geometry values need their proper scales:</strong> all 130 Secondary Distribution section lengths exactly match straight-line distances between stored X/Y endpoints. This supports metre-based, coordinate-derived lengths; it does not establish surveyed routed conductor length. The 121.9/91.4 spacing values must not be treated as metres without checking the geometry unit convention.',
'<strong>Leading spaces in library references:</strong> every PhaseConductor2Id has a leading space, including space-only references on absent phases. Preserve them in an audit. Check whether library lookup trims whitespace before changing anything.',
'<strong>Phase completeness checked:</strong> no active phase has a blank conductor key, NULL customers or NULL kVA, and no nonzero kVA occurs on a phase absent from its section. Blank phase entries in these files therefore should not automatically be classified as missing active-phase data.',
'<strong>Topology checked:</strong> every section endpoint, load reference, meter reference and transformer section reference exists. Section and node identifiers are unique within their tables. Each file also contains one unconnected node with the bare transformer number; the .1 and .2 nodes are used by the network. This extra node is recorded for review, not assumed to be an electrical break.',
'<strong>Repeated settings are not evidence of measured data:</strong> identical ZIP percentages, 2% growth, 6-hour repair times, 5000 cost units and tap-control settings may come from common modeling defaults. The records do not establish who measured or approved these values.',
'<strong>Year labels and CRS declaration:</strong> 2015–2025 are slot labels; only generation has year-specific records, all at slot 0. WGS 1984 UTM Zone 43N is the declared projection, while ProjectionWKT is empty. Confirm the zone against the actual survey before mapping; this review did not geolocate or validate the coordinate system.'
]
parts+=['<li>'+x+'</li>' for x in review];parts.append('</ol>')
parts.append('<h2>5. Tables that contain records</h2>')
parts.append(table(['Table','Records across seven files','Purpose'],[[t['name'],sum(d['tables_by_name'][t['name']]['count'] for d in dbs),TABLES[t['name']]] for t in dbs[0]['tables'] if any(d['tables_by_name'][t['name']]['count'] for d in dbs)]))
parts.append('<p>The Excel Table inventory lists every one of the 50 tables and its record count per file, including all 41 tables empty across the supplied set. Empty-table columns have no stored record values to explain, so they are not included in the 257-field dictionary.</p>')
parts.append('<h2 id="dictionary">6. Complete field dictionary and observed values</h2><p>Values are extracted facts. Meanings labelled as interpretations are inferred from field names and electrical conventions, with unresolved unit scales and enums called out. Repeated values show their occurrence count across all seven files. The Excel All entries sheet retains every record rather than only examples.</p><input id="search" placeholder="Filter by field, table, value or purpose" aria-label="Filter field dictionary">')
parts.append(table(['Table','Field','Unit / form','Short meaning','Interpretation / caution','Observed values (sample; n=count)'],[[r[0],r[1],r[2],r[3],r[4],r[6]] for r in fields],'dictionary'))
parts.append('<h2 id="sources">7. Sources, extraction and limits</h2><p>All supplied databases were opened read-only using Microsoft ACE OLE DB. All rows and columns in all 50 ordinary user tables were read. Source SHA-256 hashes were checked before and after extraction and were unchanged. The extraction covers stored table data; it does not execute Access macros, queries or any text instructions inside records, and it does not run the SynerGEE solver. No instructions embedded in data were treated as user instructions.</p>')
parts.append('<p>The user describes these files as approved. This report explains their stored contents; it does not independently establish an approval workflow or an engineering certification. The blank LastSavedBy/LastSavedDate fields do not identify the approving person or date.</p>')
parts.append(table(['Ref.','External reference','Used for'],[[x[0],x[1],x[3]] for x in SOURCES]))
parts.append('<ul>'+''.join('<li><span class="tag">'+s[0]+'</span> <a href="'+s[2]+'">'+esc(s[1])+'</a></li>' for s in SOURCES)+'</ul>')
parts.append('<h3>Source files and integrity hashes</h3>'+table(['Source filename','SHA-256 (unchanged after extraction)'],[[d['file'],d['sha256']] for d in dbs]))
parts.append('<p class="small">The supplied files are the primary source for every stored value. External references support general interpretation only; they do not verify a particular field value, legacy enum or equipment definition.</p><script>document.getElementById("search").addEventListener("input",function(){const q=this.value.toLowerCase();document.querySelectorAll(".dictionary tbody tr").forEach(r=>r.hidden=!r.textContent.toLowerCase().includes(q))})</script></main></html>')
(ROOT/'Synergee_MDB_Analysis.html').write_text('\n'.join(parts),encoding='utf-8')
print(json.dumps({'files':len(dbs),'records':sum(s['rows'] for s in summaries),'field_values':len(entries),'dictionary_fields':len(fields),'sections':len(sections),'loads':len(loads),'nodes':len(nodes),'settings':len(settings),'length_total':sum(s['length'] for s in summaries)},indent=2))
