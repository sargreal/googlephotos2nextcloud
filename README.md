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
(relative to your Nextcloud files, e.g. `/Takeout`). Instead of uploading, the files are then moved on the server:

- every photo/video is moved to `<NEXTCLOUD_URL path>/<year>/<month>/`, using the taken time from its JSON sidecar
  (falling back to the date Nextcloud extracted from EXIF, then the modification time)
- files whose contents already exist below the target path are deleted (they end up in the Nextcloud trash bin);
  set `DELETE_DUPLICATES=false` to leave them in place
- files in album directories (directories with a `metadata.json`) are added to the matching Nextcloud Photos album,
  which is created when missing
- the JSON sidecars stay in the takeout directory, which can be removed once you are happy with the result

No local files or Imagick are needed for this mode. Run with `DRY_RUN=true` first to see what would happen.

```dotenv
NEXTCLOUD_URL=https://user:s3cr3t@cloud.example.com/Photos
NEXTCLOUD_TAKEOUT_PATH=/Takeout
DRY_RUN=true # Optional, only log what would be done
DELETE_DUPLICATES=false # Optional, keep duplicates in the takeout directory
```

```sh
docker run --rm -e NEXTCLOUD_URL=<NEXTCLOUD_URL> -e NEXTCLOUD_TAKEOUT_PATH=/Takeout -e DRY_RUN=true gp2nc
```

## Known errors

Disk cache limit is set to 32GiB, files requiring larger cache will exhaust this and result in an error like:

```text
Failed reading metadata: cache resources exhausted
```

## If you like this tool

Donations are [welcome](https://rikmeijer.github.io)
