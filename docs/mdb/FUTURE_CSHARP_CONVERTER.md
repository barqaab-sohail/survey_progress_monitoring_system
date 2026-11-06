# Future recommendation: C# JSON-to-MDB converter

Recorded: 6 November 2026.

Status: recommendation saved for future implementation. This document does not change the existing exporter or claim that a C# converter has been implemented.

## Recommended approach

Keep the existing PHP application and its local JSON upload interface. Use a C# (.NET) console application on the local Windows computer to convert the uploaded JSON into a Microsoft Access `.mdb` file. The local PHP application can invoke this converter.

Workflow:

1. Download the JSON export from the PHP application.
2. Upload the JSON through the PHP interface running on the local Windows computer.
3. Local PHP invokes the C# console converter with the input file and output destination.
4. The converter validates the JSON, generates the MDB, and reports the result to PHP.
5. The PHP interface presents the generated file or actionable errors.

PHP execution must happen on the Windows computer that runs the converter. A remotely hosted PHP application cannot directly launch an executable on the user's computer through a normal browser upload.

## Why C#

C# is the recommended language for this Windows conversion component because it supports a maintainable command-line application and integration with Microsoft's Access database tooling. PHP remains responsible for the existing application and user interface.

Prefer copying an approved empty MDB template that already contains the required tables, fields, and indexes, then inserting the validated JSON data. Preserve the schema and MDB format required by the consuming application.

Confirm the supported .NET version, Access engine/provider, and matching 32-bit or 64-bit architecture during implementation. Package the necessary installation instructions. Microsoft documents DAO database creation and the Jet 4.0 format here: https://learn.microsoft.com/en-us/office/client-developer/access/desktop-database-reference/dbengine-createdatabase-method-dao

## Interface alternatives

- C# console application: preferred when retaining the local PHP upload interface; also suitable for automation.
- Windows Forms: optional if a standalone desktop tool with file selection, progress, and error messages is wanted later.
- WPF: optional if a more customized desktop interface is needed.

Windows Forms is not required to generate MDB files.

## Implementation considerations

Review the existing MDB worker, JSON contract, export mappings, and template requirements before implementing this recommendation. Reuse the established mapping and validation rules. Define clear exit codes and structured results for PHP, validate input and output paths, and remove incomplete output after failures. Verify the resulting MDB schema and record counts against the JSON and test opening it in the consuming application.
