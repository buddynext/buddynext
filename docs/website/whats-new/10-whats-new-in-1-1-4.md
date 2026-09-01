# What's New in 1.1.4

BuddyNext 1.1.4 is a single fix, and it is one you should take promptly if you updated to 1.1.3.

> **Note:** BuddyNext free and BuddyNext Pro are released together. If you run both, update them at the same time so they stay in step.

## The Activity page could go blank after an update

After updating, some members found the Activity page blank - no feed, no composer, nothing. Others on the same site saw it working normally, which made it look random. Clearing the browser cache fixed it, so it also looked like it had fixed itself.

The cause was stale JavaScript. BuddyNext's feed is built from several JavaScript modules that load each other. Those modules were being requested without a version marker, so a browser that had cached one of them from the previous release could keep serving the old copy while loading new copies of the rest. The old and new modules then disagreed about what they exported, the page stopped rendering at that point, and the member got an empty screen.

Every feed and media module now carries a version marker, so a browser is never able to mix a cached module from one release with a fresh one from another. When a release changes a module, the browser fetches it.

Nothing is required of you beyond updating. Members who are currently affected will recover on their next page load; they do not need to clear anything by hand.
