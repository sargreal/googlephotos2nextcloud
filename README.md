# Google Photos to Nextcloud

Requires Nextcloud Photos app to be installed, because of albums.

Replace NEXTCLOUD_URL with the following: `https://<USER>:<APP_PASSWORD>@nextcloud.example.org/Photos`,
`/Photos` can be any path in Nextcloud to import photos to

Optionally, setting a NEXTCLOUD_PREFIX allows nextcloud to be in a sub dir.

```dotenv
# nextcloud url: https://web.hos.st/nextcloud
NEXTCLOUD_PREFIX=nextcloud # no leading or trailing /!
```

## Command Line

Run from command line (in `/path/to/Takeout/Google Photos`, where photo directories and jsons are residing)

Note: Imagick extension required (with ffmpeg for video exif data)

```dotenv
NEXTCLOUD_URL=<NEXTCLOUD_URL> # e.g. https://user:s3cr3t@cloud.example.com/Photos
NEXTCLOUD_USER=usernamehere # Optional, overwrites user in NEXTCLOUD_URL
NEXTCLOUD_PASSWORD=secret # Optional, overwrites password in NEXTCLOUD_URL
```

```sh
php gp2nc.php
```

## Run with docker

`/path/to/Takeout/Google Photos` is the path where the general json files (e.g. user-generated-memory-titles.json) of your Takeout reside.

```sh
docker build . -t gp2nc
docker run --rm -v "/path/to/Takeout/Google Photos":/photos -e NEXTCLOUD_URL=<NEXTCLOUD_URL> gp2nc
```

or use pre-built image from GitHub Container Registry (auto-build)

```sh
docker run --rm -v "/path/to/Takeout/Google Photos":/photos -e NEXTCLOUD_URL=<NEXTCLOUD_URL> ghcr.io/rikmeijer/googlephotos2nextcloud:latest
```

## Import a takeout that is already extracted in Nextcloud

If you already uploaded and extracted your Takeout into Nextcloud, set `NEXTCLOUD_TAKEOUT_PATH` to its location
(relative to your Nextcloud files, e.g. `/Takeout`). Instead of uploading, the files are then moved on the server.

A takeout split over several exports does not need to be merged first: put the extracted exports below one folder
(e.g. `/Takeouts/takeout-001`, `/Takeouts/takeout-002`) or list several paths separated by `;`
(e.g. `/Takeout;/Takeout (1)`). Folders with the same name in different exports are treated as one, so a photo's
JSON sidecar or an album's `metadata.json` may be in another export than the photo itself.

- every photo/video is moved to `<NEXTCLOUD_URL path>/<year>/<month>/`, using the taken time from its JSON sidecar
  (falling back to the date Nextcloud extracted from EXIF, then the modification time)
- the modification and creation time are set to the taken time. Nextcloud Photos only calculates its date when a
  file is created, so files without an EXIF date are copied on the server and the original is deleted instead of
  being moved (they get a new file id)
- files already in the target whose date in Nextcloud Photos does not match their year/month directory (e.g. moved
  by an earlier version of this tool) are repaired the same way, when their JSON sidecar is still in the takeout
- files whose contents already exist below the target path are deleted (they end up in the Nextcloud trash bin);
  set `DELETE_DUPLICATES=false` to leave them in place
- files in album directories (directories with a `metadata.json`) are added to the matching Nextcloud Photos album,
  which is created when missing
- the JSON sidecars stay in the takeout directory, which can be removed once you are happy with the result

No local files or Imagick are needed for this mode. Run with `DRY_RUN=true` (or `php gp2nc.php --dry-run`) first
to see what would happen; the first log line says `(dry run, nothing will be changed)` when it is active.

```dotenv
NEXTCLOUD_URL=https://user:s3cr3t@cloud.example.com/Photos
# one path, or several separated by ;
NEXTCLOUD_TAKEOUT_PATH=/Takeouts
# Optional, only log what would be done
DRY_RUN=true
# Optional, keep duplicates in the takeout directory
DELETE_DUPLICATES=false
```

```sh
docker run --rm --env-file gp2nc.env gp2nc
# or
docker run --rm -e NEXTCLOUD_URL=<NEXTCLOUD_URL> -e NEXTCLOUD_TAKEOUT_PATH=/Takeouts gp2nc /app/gp2nc.php --dry-run
```

## Repair jobs

Two jobs tidy up media files that are already below the `NEXTCLOUD_URL` path. Select one with `JOB=<name>` or as
argument (`php gp2nc.php <name>`); `DRY_RUN`, `--dry-run` and `DELETE_DUPLICATES` work as for the import.

- `reorganize`: moves photos and videos that are not in the `<year>/<month>` directory of their date. The date is
  taken from EXIF, else from the file name (see below), else from the modification time. Files already in a
  `<year>/<month>` directory are only moved when their date comes from EXIF or the file name, because the
  modification time is often just the upload time.
- `filename-dates`: for photos and videos without an EXIF date whose name contains a date (`IMG_20190504.jpg`,
  `IMG_20190504_142512.jpg`, `IMG-20190504-WA0001.jpg`, `PXL_…`, `VID_…`, `Screenshot_2019-05-04-14-25-12.png`, …),
  moves them to the `<year>/<month>` directory of that date and sets their dates. Useful for takeout files without
  JSON metadata, which the import puts in the month of the export.

Both jobs set the modification and creation time, keep album memberships (a copy is used when needed to update the
date in Nextcloud Photos, see above) and delete files that are identical to another file below the target. Files
that are not photos or videos are ignored.

```dotenv
NEXTCLOUD_URL=https://user:s3cr3t@cloud.example.com/Photos
JOB=filename-dates
DRY_RUN=true
# Optional, directories (relative to your Nextcloud files) to leave alone, separated by ;
EXCLUDE_PATHS=/Photos/Wallpapers;/Photos/Scans
# Optional, timezone of the times in file names (default UTC)
TZ=Europe/Berlin
```

```sh
docker run --rm --env-file gp2nc.env gp2nc
# or
docker run --rm --env-file gp2nc.env gp2nc /app/gp2nc.php reorganize --dry-run
```

## Known errors

Disk cache limit is set to 32GiB, files requiring larger cache will exhaust this and result in an error like:

```text
Failed reading metadata: cache resources exhausted
```

## If you like this tool

Donations are [welcome](https://rikmeijer.github.io)
