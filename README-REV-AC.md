# Paint Project Manager V4.0 · Rev AC

## Seasonal behavior
- Fall: September 1 until the Thanksgiving override.
- Thanksgiving: seven days before the fourth Thursday of November through Thanksgiving Day.
- Christmas: the day after Thanksgiving through December 25.
- Winter: December 26 through March 11, then again after March 17 until the next seasonal package.
- St. Patrick's Day: March 12–17.
- Theme selection uses the device's local date at app launch/reload.

## Preview URLs
Append one of these to `index.html`: `?theme=fall`, `?theme=thanksgiving`, `?theme=christmas`, `?theme=winter`, or `?theme=stpatrick`.

## Deployment
Copy the entire package, preserving `assets/seasons/`, to both the GitHub web root and `/ppm/`. Do not copy seasonal files to `/ppm-api/`.

## Asset note
The supplied Christmas, Winter, St. Patrick's Day, and Fall artwork consists of one portrait Home image per theme. Rev AC uses each supplied image for the portrait and wide-screen Home layouts and for that theme's splash, with independent percentage maps and badge anchors. The existing approved Thanksgiving desktop/mobile Home and splash assets are retained.
