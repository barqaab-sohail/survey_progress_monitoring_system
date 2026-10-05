@extends('layouts.legal')
@section('title', 'Privacy Policy')
@section('content')
<p>This policy explains how HAZECO Survey App at hazeco.barqaab.pk handles information. The application supports the HAZECO Transmission and Distribution Losses Calculation Project and is operated for project activities by BARQAAB Consulting Services.</p>
<h2>Information we collect and use</h2>
<p>We store account details including name, email, phone number where provided, organization, role, status, and a hashed password. Project records include team and feeder assignments, survey quantities, MDB records, verification decisions, remarks, evidence links, and uploaded master data or GIS files. Sessions, request logs, and audit records support operation and security.</p>
<p>We use this information to authenticate authorized users, manage assignments, record progress, review evidence, produce reports, maintain an audit history, provide support, and protect the portal from misuse.</p>
<h2>Google Drive and Google user data</h2>
<p>Google Drive connection is optional. You can submit evidence by pasting a Drive URL without connecting your Google account. Pasting a URL stores the link; it does not grant the portal access to your Drive account. Opening it is subject to Google's access controls and your sharing permissions.</p>
<p>If you connect Google Drive, Google handles authentication and consent. We do not receive your Google password. The portal requests <code>drive.file</code> permission for individual files created by the application or explicitly selected or shared with it through supported Google authorization flows. This does not grant general access to all Drive files.</p>
<p>The current connection stores an access token, a refresh token when supplied, the authorized scope, and token expiry time. Credentials are encrypted in the application database and associated with your project account. The current application does not automatically upload, download, list, or inspect Drive files through this connection. We will disclose changes and obtain additional consent where required before introducing new uses of Google data.</p>
<p>Google user data is used only for authorized project features. We do not sell it, use it for advertising, or use it to train generalized artificial intelligence or machine learning models. The portal's use and transfer of information received from Google APIs will adhere to the <a href="https://developers.google.com/terms/api-services-user-data-policy">Google API Services User Data Policy</a>, including its Limited Use requirements.</p>
<h2>Sharing and access</h2>
<p>Project records and evidence links are available to authorized personnel according to their roles and assignments. Drive links require separate permission from the file owner. Hosting and infrastructure providers may process information as needed to operate the service. Information may be disclosed where required by law or to investigate security incidents. Google processes authorization requests under its own privacy policy.</p>
<h2>Storage and security</h2>
<p>We use authentication, role restrictions, password hashing, encrypted Google credentials, and audit records to protect information. Google authorization and token exchange use HTTPS. No storage or transmission method guarantees complete security. Keep credentials private and share evidence only with appropriate project users.</p>
<h2>Retention and deletion</h2>
<p>Records are retained as needed for project operations, verification, audit obligations, and applicable retention requirements. Retention periods vary by record type. Contact us to request access, correction, or deletion of personal information or Google connection data. We may need to verify your identity. Records required for accountability or legal obligations may be retained, and backups may remain until the relevant backup retention cycle ends.</p>
<p>Selecting <strong>Disconnect Google Drive</strong> removes stored Google credentials from the active application database. It does not delete Drive files, evidence links, or Google's permission grant. To revoke permission, remove the application's access from your <a href="https://myaccount.google.com/connections">Google Account connections</a>.</p>
<h2>Cookies</h2>
<p>The portal uses session and security cookies to maintain sign-in and protect forms. Selecting remember-me may keep you signed in across visits. These cookies support operation of the service.</p>
<h2>Updates and contact</h2>
<p>Updates will be published here with a revised effective date. For privacy questions, Google data deletion requests, or corrections, contact the project administrator at <a href="mailto:sohail.afzal08@gmail.com">sohail.afzal08@gmail.com</a>.</p>
@endsection
