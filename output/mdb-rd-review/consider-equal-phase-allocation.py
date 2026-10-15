"""Record the user-selected equal allocation method and demonstrate it on the R&D transcription."""
from pathlib import Path
from fractions import Fraction
import csv,json,math

BASE=Path(__file__).resolve().parent
OUT=BASE/'phase-allocation';OUT.mkdir(exist_ok=True)
sample=json.loads((BASE/'sample-transcription.json').read_text())
method={'method_id':'equal_connected_phases_v1','method_source':'User instruction: single phase consumer are equally divided on each phase',
 'scope':'Categories established as single-phase; RS, SC and SI in the current printed-code interpretation',
 'allocation':'customers_per_phase = verified_category_count / number_of_connected_R_Y_B_phases',
 'neutral':'N is excluded from the allocation denominator',
 'three_phase_span':'Divide equally between R, Y and B',
 'two_phase_span':'Divide equally between the two connected phases',
 'one_phase_span':'Allocate the full count to the connected phase',
 'fractional_values':'Use fractional equivalent customers without rounding to whole customers',
 'source_counts':'Keep original surveyed category totals as integers; leave unknown totals unresolved',
 'demand':'Once a category total demand is defined, the same shares can allocate its kW/kvar/kVA; this method does not determine total demand',
 'three_phase_customers':'Excluded from this single-phase rule; retain their three-phase classification',
 'application_status':'Recorded in R&D; not activated in live configuration or implemented as automatic application allocation',
 'required_application_changes':['Generate per-phase customer shares from the verified count and connected phases',
 'Allow fractional equivalent customers for this documented method in NetworkValidator and the demand-entry control',
 'Validate that allocations sum to the surveyed count using a floating-point tolerance, as the exporter already does',
 'Preserve original integer counts, method provenance and per-category demand evidence in revision snapshots']}
(OUT/'equal-phase-method.json').write_text(json.dumps(method,indent=2),encoding='utf-8')
rows=[]
for section in sample['sections']:
 active=[p for p in 'RYB' if p in section['phase_code_interpreted']]
 assert active
 for category,count in section['consumer_counts_read'].items():
  eligible=category in ['RS','SC','SI']
  status='allocated' if eligible and count is not None else 'unknown_count' if eligible else 'category_phase_classification_unresolved'
  shares={p:Fraction(count,len(active)) if eligible and count is not None and p in active else None for p in 'RYB'}
  row={'section_id':section['section_id'],'pdf_page':section['page'],'endpoint_identity':section['end_identity'],
   'category':category,'original_consumer_count':count,'connected_phases':''.join(active),'status':status}
  for p in 'RYB':
   row[p+'_equivalent_customers']=float(shares[p]) if shares[p] is not None else None
   row[p+'_exact_share']=str(shares[p]) if shares[p] is not None else None
  if status=='allocated':
   assert sum(v for v in shares.values() if v is not None)==count
   assert abs(sum(row[p+'_equivalent_customers'] or 0 for p in 'RYB')-count)<1e-8
  rows.append(row)
(OUT/'page11-customer-allocation.json').write_text(json.dumps(rows,indent=2),encoding='utf-8')
with (OUT/'page11-customer-allocation.csv').open('w',newline='',encoding='utf-8-sig') as f:
 w=csv.DictWriter(f,fieldnames=list(rows[0]));w.writeheader();w.writerows(rows)
references=[]
for f in sorted(BASE.glob('*.inspection.json')):
 if f.name in ['sample.inspection.json','template.inspection.json']:continue
 d=json.loads(f.read_text(encoding='utf-8-sig'))
 loads=next(t['rows'] for t in d['tables'] if t['name']=='Loads')
 fractional=[r for r in loads if any(isinstance(r.get(f'Phase{i}Customers'),(float,int)) and r[f'Phase{i}Customers']%1!=0 for i in [1,2,3])]
 equal=[r for r in fractional if r['Phase1Customers']==r['Phase2Customers']==r['Phase3Customers']]
 references.append({'file':d['file'],'fractional_customer_rows':len(fractional),'equal_three_phase_fractional_rows':len(equal)})
(OUT/'reference-allocation-evidence.json').write_text(json.dumps(references,indent=2),encoding='utf-8')
assert sum(r['equal_three_phase_fractional_rows'] for r in references)==69
report='''**Agreed R&D phase-allocation method: equal shares for single-phase consumers**

The user specified that single-phase consumers are divided equally across phases. This resolves the choice of estimation rule for single-phase customer allocation. Use the phases actually connected on the span: RYB gives thirds; a two-phase span gives halves; a one-phase span carries the whole count. Neutral is not a load phase.

| Single-phase consumers | Connected phases | R equivalent customers | Y equivalent customers | B equivalent customers |
|---:|---|---:|---:|---:|
| 6 | RYB | 2 | 2 | 2 |
| 10 | RYB | 10/3 | 10/3 | 10/3 |
| 5 | YB | absent | 2.5 | 2.5 |
| 4 | B | absent | absent | 4 |

These are equivalent model shares, not fractional actual persons or fabricated observed connections. Keep the surveyed integer category total. Avoid rounding thirds to whole customers or allocating loads to a missing phase. Categories known as single-phase in the current survey legend are RS, SC and SI. This rule does not relabel RL or LI three-phase consumers or guess the phase classification of LC/PB/AG/ST.

The nine valid reference MDBs contain 80 load rows with fractional phase customer counts, including 69 rows with equal fractional values in all three phase fields. This supports the compatibility of equivalent shares with the reference format. It does not establish a demand per customer or the meaning of every historical load row.

**Demonstration on PDF page 11**

`page11-customer-allocation.csv` and `.json` apply the method to the existing R&D transcription. For example, five SC consumers at WP092 receive 5/3 per phase, four RS consumers at the blue-only WP093 receive four on B, and three RS consumers at the YB WP099 receive 1.5 on Y and 1.5 on B. The overwritten SC count at WP091 stays unknown. LC stays unresolved because its phase classification has not been established. No kW/kvar/kVA or unknown category zeros were invented. These readings still require the normal source-row verification when entered into the application.

**Effect on the feasibility review**

The single-phase allocation rule is now available as a user-specified method; actual phase-by-phase observations are no longer a prerequisite for this estimated model. A category demand/estimation method is still needed to establish total kW/kvar/kVA. Once a category total demand is established, it can be divided using these same shares.

MDB phase-customer fields are floating-point fields, and the reviewed exporter already accepts nonnegative numeric shares and checks their sum within a tolerance. However, `NetworkValidator` currently enforces integer per-phase customers, and the demand-entry control has `step=1`. Automatic application support must generate the fractional shares, relax that restriction for the documented estimation method, and preserve the original integer category counts and provenance. An export write succeeding does not bypass the application validation requirement.

This update records and demonstrates the method in the R&D review. It does not change live project configuration, approve a demand record, or update the previously generated incomplete MDB. That MDB retains its original documented scope and NULL phase-demand values.

Files: [method definition](equal-phase-method.json), [page-11 allocation](page11-customer-allocation.csv), [reference evidence](reference-allocation-evidence.json).
'''
(OUT/'PHASE_ALLOCATION_METHOD.md').write_text(report,encoding='utf-8')
print(json.dumps({'demonstration_rows':len(rows),'allocated_rows':sum(r['status']=='allocated' for r in rows),
 'fractional_reference_rows':sum(r['fractional_customer_rows'] for r in references),
 'equal_three_phase_fractional_reference_rows':69,'report':str(OUT/'PHASE_ALLOCATION_METHOD.md')},indent=2))
