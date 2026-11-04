# Email and Push Notification Issues

Problems with BuddyNext emails that never arrive and with web push notifications that never reach a member's device.

## A member says an email never arrived

**Symptom:** A member reports a verification email, a notification email or a digest that did not show up.

**Likely cause:** Either BuddyNext never sent it (the template is off, or the member turned that email off), or it sent and the mail was lost between your site and the inbox. The Email Log tells you which.

**Fix:**
1. Open **BuddyNext > Notifications > Email Log** and search for the member by name, username or email.
2. A **Sent** row means BuddyNext handed the message to WordPress. From there the trail continues with your email delivery: your SMTP plugin or your host. Ask the member to check spam and promotions folders.
3. A **Failed** row shows the error WordPress returned. Fix that at the mail layer first.
4. No row at all means nothing was sent. Check that the template is switched on under **Notifications > Email Templates**, and that the member has not turned that email type off in their notification preferences.
5. Send a test from the template editor. It goes out through the same sender and wrapper as a real email, so if the test does not arrive, the problem is your site's mail setup rather than the template.

To improve delivery, set the From address to an address on a domain you own, and pair BuddyNext with an email-delivery (SMTP) plugin so a real provider sends the mail instead of your web server.

See [Transactional Email System](../messaging-notifications/04-email-system.md), [Notification Preferences](../messaging-notifications/03-notification-preferences.md) and, for verification emails in particular, [Members and Login Issues](03-members-and-login-issues.md).

## Digest emails are not arriving

**Symptom:** Members expect a daily or weekly digest and do not get one.

**Likely cause:** A digest only includes types the member set to Daily or Weekly. It collects what is still unread from the period, so a member who reads everything in the app may have an empty period, and BuddyNext does not send a digest with nothing in it. That is intended.

**Fix:** Check the member's email frequency for each type in their notification preferences (Immediate, Daily, Weekly or Off), then look for the digest in the Email Log (a digest row also shows the day it covers).

See [Email Digests](../messaging-notifications/05-email-digests.md).

## Web push is set up but nothing reaches members

**Symptom:** You configured push, but members do not see banners on their phone or desktop.

**Likely cause:** Web push is a Pro feature with several separate requirements, and a gap in any one of them stops delivery without an error on the member's side.

**Fix:** Work through these in order.
1. Confirm BuddyNext Pro is active. Free shows in-app notifications and can email, but it cannot deliver to a device when the member is off the site.
2. In the Features catalogue, switch on **Push notifications**. It is off by default.
3. Under **Realtime & Push > Push**, enter the Firebase project ID and the service-account JSON.
4. Fill in all four web fields: Web API key, Messaging sender ID, Web app ID and the VAPID public key. Leaving them blank disables browser push, even if the first two fields are correct.
5. Check that the site is served over HTTPS. Browsers only allow push on secure origins.
6. Use **Send test push** on the same page and read the result message. "Push is not configured" means the project ID or JSON is missing. "Push delivery is currently disabled" means the Features switch is off. "Sent, but you have no registered devices" means your credentials are fine and you have not enabled browser push on your own preferences page yet, so enable it for yourself and test again.

See [Push Notifications](../pro/17-push-notifications.md).

## Push works for the admin but a member gets nothing

**Symptom:** Your own test push arrives, but a particular member never receives one.

**Likely cause:** Push is delivered per member, to devices that member registered, and it respects their per-type preferences.

**Fix:**
- The member has to opt in to browser push from their own notification preferences. Nobody can enable it on their behalf.
- A member who muted a notification type will not get a push for it, although it still appears in their in-app notifications.
- If the member revoked the permission in the browser or uninstalled the app, the stale device is removed automatically once Firebase reports it as gone. They need to opt in again.

See [Notification Preferences](../messaging-notifications/03-notification-preferences.md).

## The bell or the "new posts" pill is slow

This is expected on Free, which polls rather than holding a live connection. See [Performance and Cache Issues](04-performance-and-cache.md) for the intervals and the Pro real-time option.

## Related

- [Transactional Email System](../messaging-notifications/04-email-system.md)
- [Email Digests](../messaging-notifications/05-email-digests.md)
- [Push Notifications](../pro/17-push-notifications.md)
- [Install as a Progressive Web App](../engagement/04-pwa.md)
