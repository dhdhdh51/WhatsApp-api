package site.bharatseo.bcreport;

import android.app.AlarmManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.os.Build;

import java.util.Calendar;
import java.util.TimeZone;

/** Schedules the daily call (default 17:00) and alarm (default 17:30) on the phone itself – exact, no internet needed. */
final class AlarmScheduler {
    static final String ACTION_RING = "site.bharatseo.bcreport.RING";

    private AlarmScheduler() {
    }

    static void scheduleAll(Context c) {
        schedule(c, "call", Prefs.callTime(c), 101);
        schedule(c, "alarm", Prefs.alarmTime(c), 102);
    }

    static final int RC_REPEAT = 103;
    static final int REPEAT_MIN = 10;

    private static PendingIntent repeatIntent(Context c) {
        Intent i = new Intent(c, AlarmReceiver.class).setAction(ACTION_RING).putExtra("mode", "alarm").putExtra("repeat", true);
        return PendingIntent.getBroadcast(c, RC_REPEAT, i, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    /** Ring again in REPEAT_MIN minutes, until the report is submitted (or the cutoff). */
    static void scheduleRepeat(Context c) {
        AlarmManager am = c.getSystemService(AlarmManager.class);
        if (am == null || Prefs.token(c).isEmpty()) {
            return;
        }
        long at = System.currentTimeMillis() + REPEAT_MIN * 60_000L;
        if (Build.VERSION.SDK_INT < 31 || am.canScheduleExactAlarms()) {
            PendingIntent show = PendingIntent.getActivity(c, RC_REPEAT, new Intent(c, MainActivity.class),
                    PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
            am.setAlarmClock(new AlarmManager.AlarmClockInfo(at, show), repeatIntent(c));
        } else {
            am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, at, repeatIntent(c));
        }
    }

    static void cancelRepeat(Context c) {
        AlarmManager am = c.getSystemService(AlarmManager.class);
        if (am != null) {
            am.cancel(repeatIntent(c));
        }
    }

    private static PendingIntent pending(Context c, String mode, int rc) {
        Intent i = new Intent(c, AlarmReceiver.class).setAction(ACTION_RING).putExtra("mode", mode);
        return PendingIntent.getBroadcast(c, rc, i, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    private static void schedule(Context c, String mode, String hhmm, int rc) {
        AlarmManager am = c.getSystemService(AlarmManager.class);
        if (am == null) {
            return;
        }
        PendingIntent p = pending(c, mode, rc);
        am.cancel(p);
        if (!Prefs.alarmEnabled(c) || Prefs.token(c).isEmpty() || hhmm == null || !hhmm.matches("\\d{1,2}:\\d{2}")) {
            return;
        }
        String[] t = hhmm.split(":");
        Calendar cal = Calendar.getInstance(TimeZone.getTimeZone("Asia/Kolkata"));
        cal.set(Calendar.HOUR_OF_DAY, Integer.parseInt(t[0]));
        cal.set(Calendar.MINUTE, Integer.parseInt(t[1]));
        cal.set(Calendar.SECOND, 0);
        cal.set(Calendar.MILLISECOND, 0);
        if (cal.getTimeInMillis() <= System.currentTimeMillis() + 1000) {
            cal.add(Calendar.DAY_OF_YEAR, 1);
        }
        long at = cal.getTimeInMillis();
        boolean exact = Build.VERSION.SDK_INT < 31 || am.canScheduleExactAlarms();
        if (exact) {
            PendingIntent show = PendingIntent.getActivity(c, rc, new Intent(c, MainActivity.class),
                    PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
            am.setAlarmClock(new AlarmManager.AlarmClockInfo(at, show), p);
        } else {
            am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, at, p);
        }
    }
}
