package site.bharatseo.bcreport;

import android.app.admin.DevicePolicyManager;
import android.content.ComponentName;
import android.content.Context;

/**
 * Company-owned/consented-device mode. This is intentionally dormant unless the app is provisioned
 * as the Android Device Owner during device setup. A normal side-loaded app remains removable.
 */
final class Mdm {
    private Mdm() { }

    static ComponentName admin(Context c) {
        return new ComponentName(c, MdmAdminReceiver.class);
    }

    static boolean isDeviceOwner(Context c) {
        DevicePolicyManager dpm = c.getSystemService(DevicePolicyManager.class);
        return dpm != null && dpm.isDeviceOwnerApp(c.getPackageName());
    }

    /** Report device-owner enrollment to the server for the Admin MDM status page. */
    static void report(Context c, String token) {
        if (token == null || token.isEmpty()) {
            return;
        }
        final boolean owner = isDeviceOwner(c);
        new Thread(() -> {
            java.net.HttpURLConnection con = null;
            try {
                con = (java.net.HttpURLConnection) new java.net.URL(BuildConfig.BASE_URL + "/api/app.php?a=mdm").openConnection();
                con.setRequestMethod("POST");
                con.setDoOutput(true);
                con.setConnectTimeout(8000);
                con.setReadTimeout(8000);
                con.setRequestProperty("Authorization", "Bearer " + token);
                con.setRequestProperty("Content-Type", "application/json");
                byte[] body = ("{\"device_owner\":" + (owner ? "true" : "false") + "}").getBytes(java.nio.charset.StandardCharsets.UTF_8);
                try (java.io.OutputStream o = con.getOutputStream()) { o.write(body); }
                con.getResponseCode();
            } catch (Exception ignored) {
            } finally {
                if (con != null) con.disconnect();
            }
        }).start();
    }

    /** Device Owner blocks normal uninstall of BC Report; removing it then requires the organization/device reset. */
    static void enforce(Context c) {
        DevicePolicyManager dpm = c.getSystemService(DevicePolicyManager.class);
        if (dpm == null || !dpm.isDeviceOwnerApp(c.getPackageName())) {
            return;
        }
        try {
            dpm.setUninstallBlocked(admin(c), c.getPackageName(), true);
        } catch (SecurityException | IllegalArgumentException ignored) {
            // Android already blocks normal removal of a Device Owner. Some OEMs reject self-blocking.
        }
    }
}
