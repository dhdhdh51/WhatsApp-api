package site.bharatseo.bcreport;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.media.AudioAttributes;
import android.media.RingtoneManager;
import android.net.Uri;

/** Shows the ringing screen (full-screen, also on the lock screen) with a looping ring / alarm sound. */
final class Ringer {
    static final String CH_CALL = "bc_call_v1";
    static final String CH_ALARM = "bc_alarm_v1";
    static final String ACTION_REPORT_DONE = "site.bharatseo.bcreport.REPORT_DONE";
    static final int NID = 7001;

    private Ringer() {
    }

    private static void channels(NotificationManager nm) {
        if (nm.getNotificationChannel(CH_CALL) == null) {
            NotificationChannel ch = new NotificationChannel(CH_CALL, "Report call", NotificationManager.IMPORTANCE_HIGH);
            ch.setSound(uri(RingtoneManager.TYPE_RINGTONE), new AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build());
            ch.enableVibration(true);
            ch.setVibrationPattern(new long[]{0, 800, 600, 800});
            ch.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
            nm.createNotificationChannel(ch);
        }
        if (nm.getNotificationChannel(CH_ALARM) == null) {
            NotificationChannel ch = new NotificationChannel(CH_ALARM, "Report alarm", NotificationManager.IMPORTANCE_HIGH);
            ch.setSound(uri(RingtoneManager.TYPE_ALARM), new AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_ALARM).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build());
            ch.enableVibration(true);
            ch.setVibrationPattern(new long[]{0, 1000, 500, 1000});
            ch.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
            nm.createNotificationChannel(ch);
        }
    }

    static Uri uri(int type) {
        Uri u = RingtoneManager.getDefaultUri(type);
        if (u == null) {
            u = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM);
        }
        if (u == null) {
            u = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION);
        }
        return u;
    }

    static void ring(Context c, String mode) {
        NotificationManager nm = c.getSystemService(NotificationManager.class);
        if (nm == null) {
            return;
        }
        channels(nm);
        boolean call = "call".equals(mode);
        Intent i = new Intent(c, RingActivity.class).putExtra("mode", mode)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_NO_USER_ACTION);
        PendingIntent full = PendingIntent.getActivity(c, call ? 201 : 202, i, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        Notification n = new Notification.Builder(c, call ? CH_CALL : CH_ALARM)
                .setSmallIcon(call ? android.R.drawable.sym_call_incoming : android.R.drawable.ic_lock_idle_alarm)
                .setContentTitle(call ? "📞 BC Report – call" : "⏰ BC Report – alarm")
                .setContentText("Aaj ki report abhi baaki hai")
                .setCategory(call ? Notification.CATEGORY_CALL : Notification.CATEGORY_ALARM)
                .setFullScreenIntent(full, true)
                .setContentIntent(full)
                .setOngoing(true)
                .setAutoCancel(true)
                .setTimeoutAfter(120000)
                .build();
        n.flags |= Notification.FLAG_INSISTENT; // keep ringing until opened
        try {
            nm.notify(NID, n);
        } catch (SecurityException ignored) {
            // notification permission not granted
        }
    }

    /** Cancel notification and signal an open RingActivity to close (report submitted). */
    static void stopActive(Context c) {
        cancel(c);
        Intent i = new Intent(ACTION_REPORT_DONE).setPackage(c.getPackageName());
        c.sendBroadcast(i);
    }

    static void cancel(Context c) {
        NotificationManager nm = c.getSystemService(NotificationManager.class);
        if (nm != null) {
            nm.cancel(NID);
        }
    }
}
