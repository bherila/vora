# Event attendee UX validation

The UX work is tracked in issues [#236–#242](https://github.com/bherila/vora/issues).
GitHub CI covers the frontend/backend gates, desktop and Android-sized Chromium,
and the Event browsing journey in iPhone-sized WebKit. Real-media CI separately
uses disposable Docker storage, SMTP, photo uploads, multipart videos and decoded
HLS playback. These tests do not represent physical iPhone/Android validation.

## Physical-device check before inviting attendees

Use a dedicated Event instance or disposable staging accounts. Keep production
media and credentials out of local staging. Record commit, phone model, OS,
browser version, date and results against issue #238.

On an actual iPhone using Safari and an actual Android phone using Chrome:

1. Open a joining link from a message or scan a QR code encoding the same
   `/events/join/{event-ulid}` URL. Register/sign in, verify email and complete
   required approval/two-factor steps. Check that continuing returns to the
   intended Event. If email opens another browser/device, reopen the original
   joining link in the browser where sign-in is complete.
2. Choose ten camera-roll photos and a freshly recorded short video. Confirm
   previews, destination Event and audience are clear; titles are optional.
   Confirm the file picker accepts the device's actual formats. Unsupported
   formats should produce a useful error, without losing other successful files.
3. On normal Wi-Fi, upload the batch. Read the count and per-file states. Interrupt
   the connection, restore it and retry failed files. Already successful files
   must not be uploaded again. Check that cancellation is understandable and
   does not leave the account quota reserved indefinitely.
4. Background the browser briefly and return. Do not assume the OS permits
   background uploads: the UI must explain keeping the page open and any need
   to reselect files for multipart recovery. Never promise background uploading.
5. Confirm preparing-video and awaiting-review states make sense. Approve from
   a separate admin session, let transcoding complete and check automatic
   refresh/playback. Restricted media must remain hidden from an outsider.
6. Browse photos, use previous/next and swipe on a photo. Video controls should
   remain usable. Close the viewer and check gallery scroll position. Use Back
   and Forward, and open a direct media link from another tab.
7. Favorite a photo, Save it in one tap, optionally organize it, then download
   it. Confirm the phone's actual download/share/camera-roll workflow; this
   depends on browser and OS. Check that the three actions are distinguishable.
8. Remove the attendee's restricted Event membership. New gallery/download
   access must disappear, while previously Saved items remain privately
   available. Do not use real accounts for deletion/retention testing.

Stop any reusable local services after the check. Preserve volumes and accounts.

## Unguided attendee pilot

Ask a few people using their own phones to complete these tasks without coaching:

- Join the intended Event.
- Upload ten photos.
- Find another attendee's gallery and the featured people's favorites.
- Save a photo for later and explain who can see their Favorite versus their Save.
- Download a photo and find it on their device.

Record where they hesitate, mistakes, unfinished tasks and their own wording.
Observe first; explain afterward. Track remaining changes in the matching issue.
A successful automated browser run does not mark this pilot or the physical-device
checks complete. Keep issue #238 open until device results are recorded.
