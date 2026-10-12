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
    }
}
