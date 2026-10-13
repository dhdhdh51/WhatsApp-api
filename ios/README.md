# BC Report for iPhone / iPad

The shared web app is already available on iPhone/iPad: open `https://bc.bharatseo.site/app/` in Safari, then **Share → Add to Home Screen**.
It supports login, daily reporting, history and chat.

## Native iOS app requirements (before development/signing)

A native iOS build with Firebase/APNs push requires these customer-owned Apple/Firebase resources:

1. An **Apple Developer Program** account and the Apple Team ID.
2. An iOS Bundle ID, proposed: `site.bharatseo.bcreport`.
3. `GoogleService-Info.plist` after adding this iOS app to Firebase project `bc-report-199e3`.
4. Apple APNs Authentication Key (`.p8`), its Key ID, and Team ID, uploaded to Firebase Cloud Messaging.
5. For non-removable company phones, **Apple Business Manager** plus an MDM vendor and supervised-device enrollment. Ordinary iPhones cannot be made uninstall-proof by an app.

iOS does not allow a normal app to continuously ring or simulate a persistent call in the background until a report is submitted. Native iOS can provide FCM push, local notification reminders, reporting and chat, while supervised MDM manages company-owned devices.
