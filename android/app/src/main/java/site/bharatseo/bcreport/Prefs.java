package site.bharatseo.bcreport;

import android.content.Context;
import android.content.SharedPreferences;

/** Login token and last seen notification id. */
final class Prefs {
    private static final String FILE = "bc_session";

    private Prefs() {
    }

    private static SharedPreferences p(Context c) {
        return c.getSharedPreferences(FILE, Context.MODE_PRIVATE);
    }

    static void saveSession(Context c, String token, String last) {
        SharedPreferences.Editor e = p(c).edit().putString("token", token);
        if (last != null && !last.isEmpty()) {
            try {
                long l = Long.parseLong(last);
                if (l > 0 && l > lastId(c)) {
                    e.putLong("last", l);
                }
            } catch (NumberFormatException ignored) {
            }
        }
        e.apply();
    }

    static String token(Context c) {
        return p(c).getString("token", "");
    }

    static long lastId(Context c) {
        return p(c).getLong("last", 0L);
    }

    static void setLastId(Context c, long id) {
        p(c).edit().putLong("last", id).apply();
    }

    static void clear(Context c) {
        p(c).edit().clear().apply();
        AlarmScheduler.scheduleAll(c); // token gone -> alarms cancelled
    }

    // ---- call / alarm
    static void saveAlarm(Context c, boolean enabled, String call, String alarm, String text) {
        p(c).edit().putBoolean("al_on", enabled).putString("al_call", call).putString("al_alarm", alarm).putString("al_text", text).apply();
    }

    static boolean alarmEnabled(Context c) {
        return p(c).getBoolean("al_on", true);
    }

    static String callTime(Context c) {
        return p(c).getString("al_call", "17:00");
    }

    static String alarmTime(Context c) {
        return p(c).getString("al_alarm", "17:30");
    }

    static String alarmText(Context c) {
        return p(c).getString("al_text", "");
    }

    static void setAlarmOnlyText(Context c, String t) {
        p(c).edit().putString("al_text_alarm", t).apply();
    }

    static String alarmOnlyText(Context c) {
        return p(c).getString("al_text_alarm", "");
    }

    static int alarmVersion(Context c) {
        return p(c).getInt("al_ver", -1);
    }

    static void setAlarmVersion(Context c, int v) {
        p(c).edit().putInt("al_ver", v).apply();
    }

    private static String todayIst() {
        java.text.SimpleDateFormat f = new java.text.SimpleDateFormat("yyyy-MM-dd", java.util.Locale.US);
        f.setTimeZone(java.util.TimeZone.getTimeZone("Asia/Kolkata"));
        return f.format(new java.util.Date());
    }

    /** The web app tells us when today's report is submitted, so no ring is needed even offline. */
    static void setReportDone(Context c, String date) {
        p(c).edit().putString("done_date", date).apply();
    }

    static boolean reportDoneToday(Context c) {
        return todayIst().equals(p(c).getString("done_date", ""));
    }
}
