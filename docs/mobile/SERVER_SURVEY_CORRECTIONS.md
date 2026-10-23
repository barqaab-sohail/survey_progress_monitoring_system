# Correcting uploaded mobile surveys

On **Mobile Field Surveys**, use **Edit survey** to compare the saved transformer details, S/E observations, consumer counts and solar entries with the uploaded images. The evidence panel can switch between images/sketches, open an original at full size and jump to its associated S/E row. Save corrections to create a new server revision. Stable S/E identifiers and photo links are retained. The form corrects existing observations; it does not add/remove S/E pairs or move surveys between teams/feeders.

Super Admin and Project Manager can manage visible surveys. Survey Team Leaders can manage their own surveys within the existing visibility rules. Management Viewer remains read-only. Every correction records before/after values, actor and optional correction notes in the audit log. A stale browser form cannot overwrite a newer revision.

**Delete survey** requires confirmation and removes the record from normal lists and attachment access. It uses soft deletion: the record, audit history and evidence remain retained. The deleted UUID is blocked from mobile sync so an old phone retry cannot recreate it. Existing phone copies are not remotely removed or refreshed. A retry of older data after a server correction returns a revision conflict instead of overwriting the correction.

## Deploy through Git

Run in the production Laravel directory after pulling this change:

```sh
git pull --ff-only origin main
php artisan migrate --force
php artisan route:clear
php artisan view:clear
php artisan config:cache
```

The migration adds `field_surveys.deleted_at`; apply it before serving requests with the updated model. This web feature does not require an APK update.

Verification: `FieldSurveyWebTest` and `FieldSurveyApiTest` cover editing with images, field validation, preserved row links and leading zeros/zero counts, revision conflicts, permissions, deletion audit/history and prevention of recreation by phone retry. Run these with SQLite `:memory:` and isolated configuration/route cache paths.
