# HT network updates on web and mobile

Open **HT Network Updates ? New HT Network Update ? 11 kV feeder**.
Select the feeder and load its HT connections. A completed, readable original HT KMZ is required.
The connection list includes original HT features and verified HT additions on that feeder.

Required submission fields: responsible survey team, transformer code, update type, upstream
HT feature and ordered waypoint names (connection first, transformer last). The team is selected
automatically when only one is available. Extensions can include intermediate HT poles.
Capacity, make, location description, mounting, phases, date, pole details, conductors,
photographs and remarks are optional. Coordinates can be entered now or resolved by the reviewer
using GPX. Supplied conductors and transformer ratings must match the approved warehouse.

Submitting creates a pending HT proposal. It does not add an available LT transformer yet.
The collector may edit pending or returned proposals. A different Super Admin or Project Manager
must review and verify them, resolving every coordinate and confirming the physical upstream
connection. Approval registers the transformer on the same feeder and includes the additions in
HT KMZ export. Unknown capacity remains pending field verification and must be resolved for final
engineering release. Refresh mobile references or reload PDF entry after HT verification.

Web creation is available to Super Admin, Project Manager, survey team leader, surveyor and
HT-enabled mobile survey accounts. Field users retain their existing team/feeder collection
permissions; managers must select an active team from the feeder project. Viewer and MDB roles
do not gain HT editing permission.

Both clients use the same UUID, owner, revision, feeder transaction lock, duplicate checks and
approval workflow. Stale saves receive HTTP 409 and cannot overwrite a newer revision. Reload
the current update before applying corrections. Verified updates cannot be overwritten. Editing
a proposal keeps its original feeder, team and transformer identity. Changing the source KMZ
invalidates an old connection selection and requires resubmission against the current baseline.
