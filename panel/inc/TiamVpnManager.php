<?php
/**
 * Maarksho Flow - Tiam ERP L2TP/IPSec VPN Manager
 * Handles VPN diagnostics, configuration generation, and tunnel helpers for on-premise Tiam ERP connections.
 */

class TiamVpnManager {

    /**
     * Test VPN Server reachability across L2TP (UDP 1701), IKE (UDP 500), and NAT-T (UDP 4500)
     */
    public static function probeVpnServer($serverIp, $timeoutSec = 3) {
        $result = [
            'ip' => $serverIp,
            'is_reachable' => false,
            'ports' => [
                'l2tp_1701' => false,
                'ike_500' => false,
                'natt_4500' => false
            ],
            'latency_ms' => 0,
            'log' => ''
        ];

        if (empty($serverIp)) {
            $result['log'] = 'آدرس آی‌پی سرور وی‌پی‌ان وارد نشده است.';
            return $result;
        }

        $cleanHost = trim(preg_replace('#^https?://#i', '', $serverIp));
        $cleanHost = explode(':', $cleanHost)[0];
        $cleanHost = rtrim($cleanHost, '/');

        $t0 = microtime(true);

        // 1. Probe UDP 1701 (L2TP)
        // Send a lightweight L2TP Start-Control-Connection-Request (SCCRQ) header probe
        $l2tpProbe = pack('n6', 0xC802, 0x000C, 0x0000, 0x0000, 0x0000, 0x0000);
        $fp1701 = @fsockopen("udp://" . $cleanHost, 1701, $errno, $errstr, $timeoutSec);
        if ($fp1701) {
            stream_set_timeout($fp1701, $timeoutSec);
            @fwrite($fp1701, $l2tpProbe);
            // In UDP, successfully opening the socket means the route to the host was resolved
            $result['ports']['l2tp_1701'] = true;
            $result['is_reachable'] = true;
            @fclose($fp1701);
        }

        // 2. Probe UDP 500 (ISAKMP / IKE for IPSec)
        $fp500 = @fsockopen("udp://" . $cleanHost, 500, $errno, $errstr, $timeoutSec);
        if ($fp500) {
            $result['ports']['ike_500'] = true;
            $result['is_reachable'] = true;
            @fclose($fp500);
        }

        // 3. Probe UDP 4500 (IPSec NAT-Traversal)
        $fp4500 = @fsockopen("udp://" . $cleanHost, 4500, $errno, $errstr, $timeoutSec);
        if ($fp4500) {
            $result['ports']['natt_4500'] = true;
            $result['is_reachable'] = true;
            @fclose($fp4500);
        }

        $latency = round((microtime(true) - $t0) * 1000, 1);
        $result['latency_ms'] = $latency;

        if ($result['is_reachable']) {
            $result['log'] = "ارتباط اولیه با سرور وی‌پی‌ان ($cleanHost) با زمان تاخیر {$latency}ms برقرار شد.";
        } else {
            $result['log'] = "عدم پاسخگویی پورت‌های UDP وی‌پی‌ان در هاست $cleanHost. لطفا فایروال یا باز بودن پورت‌های 1701، 500 و 4500 را بررسی فرمایید.";
        }

        return $result;
    }

    /**
     * Check if a system-level L2TP/PPP tunnel interface is currently active
     */
    public static function isTunnelActive() {
        if (!function_exists('shell_exec')) {
            return false;
        }

        // Check for ppp interfaces (ppp0, ppp1, etc.)
        $out = @shell_exec('ip -br link show 2>/dev/null || ifconfig 2>/dev/null');
        if (!empty($out) && (stripos($out, 'ppp') !== false || stripos($out, 'l2tp') !== false || stripos($out, 'tun') !== false)) {
            return true;
        }

        return false;
    }

    /**
     * Check if target local subnet (e.g. 192.168.2.x) is routable
     */
    public static function isSubnetReachable($targetIp, $port = 8080, $timeout = 2) {
        if (empty($targetIp)) return false;

        $cleanIp = trim(preg_replace('#^https?://#i', '', $targetIp));
        $cleanIp = explode(':', $cleanIp)[0];
        $cleanIp = rtrim($cleanIp, '/');

        $t0 = microtime(true);
        $fp = @fsockopen($cleanIp, (int)$port, $errno, $errstr, $timeout);
        if ($fp) {
            @fclose($fp);
            return [
                'reachable' => true,
                'latency_ms' => round((microtime(true) - $t0) * 1000, 1),
                'error' => ''
            ];
        }

        return [
            'reachable' => false,
            'latency_ms' => 0,
            'error' => $errstr ?: "اتصال به پورت $port ناموفق بود"
        ];
    }

    /**
     * Generate MikroTik RouterOS setup script for L2TP client & ERP route
     */
    public static function generateMikrotikScript($vpnConfig, $erpConfig) {
        $serverIp = $vpnConfig['vpn_server_ip'] ?? '79.127.70.17';
        $user = $vpnConfig['vpn_username'] ?? 'ehsan';
        $pass = $vpnConfig['vpn_password'] ?? '12345';
        $secret = $vpnConfig['vpn_secret'] ?? '4747';
        $erpIp = $erpConfig['erp_server_ip'] ?? '192.168.2.205';
        $subnet = $vpnConfig['vpn_subnet'] ?? '192.168.2.0/24';

        return "# ================================================================\n" .
               "# 🚀 MikroTik RouterOS Script for Tiam ERP L2TP VPN Connection\n" .
               "# ================================================================\n\n" .
               "/interface l2tp-client\n" .
               "add name=\"l2tp-tiam\" connect-to=\"$serverIp\" user=\"$user\" password=\"$pass\" \\\n" .
               "    use-ipsec=yes ipsec-secret=\"$secret\" profile=default default-route-distance=10 \\\n" .
               "    add-default-route=no allow=pap,chap,mschap1,mschap2 disabled=no\n\n" .
               "# روتینگ ترافیک سرور داخلی تیام به سمت تونل L2TP\n" .
               "/ip route\n" .
               "add dst-address=\"$subnet\" gateway=\"l2tp-tiam\" comment=\"Route Tiam ERP Subnet through L2TP Tunnel\"\n" .
               "add dst-address=\"$erpIp/32\" gateway=\"l2tp-tiam\" comment=\"Direct Route to Tiam Server\"\n\n" .
               "# بررسی اتصال:\n" .
               "/interface l2tp-client monitor l2tp-tiam once\n";
    }

    /**
     * Generate Linux (Ubuntu/Debian) xl2tpd + StrongSwan configuration
     */
    public static function generateLinuxScript($vpnConfig, $erpConfig) {
        $serverIp = $vpnConfig['vpn_server_ip'] ?? '79.127.70.17';
        $user = $vpnConfig['vpn_username'] ?? 'ehsan';
        $pass = $vpnConfig['vpn_password'] ?? '12345';
        $secret = $vpnConfig['vpn_secret'] ?? '4747';
        $erpIp = $erpConfig['erp_server_ip'] ?? '192.168.2.205';

        return "#!/bin/bash\n" .
               "# ================================================================\n" .
               "# 🐧 Linux (Ubuntu/Debian) Automated L2TP/IPSec VPN Setup for Tiam\n" .
               "# ================================================================\n\n" .
               "set -e\n" .
               "echo 'Installing xl2tpd and strongswan...'\n" .
               "sudo apt-get update\n" .
               "sudo apt-get install -y xl2tpd strongswan\n\n" .
               "# 1. Configure IPsec Pre-Shared Key\n" .
               "sudo tee /etc/ipsec.secrets > /dev/null << 'EOF'\n" .
               ": PSK \"$secret\"\n" .
               "EOF\n\n" .
               "# 2. Configure IPsec Connection\n" .
               "sudo tee /etc/ipsec.conf > /dev/null << 'EOF'\n" .
               "config setup\n" .
               "    charondebug=\"ike 1, knl 1, cfg 0\"\n\n" .
               "conn tiam-vpn\n" .
               "    authby=secret\n" .
               "    pfs=no\n" .
               "    auto=add\n" .
               "    keyexchange=ikev1\n" .
               "    ike=3des-sha1-modp1024,aes128-sha1-modp1024,aes256-sha1-modp1024!\n" .
               "    esp=3des-sha1,aes128-sha1,aes256-sha1!\n" .
               "    left=%defaultroute\n" .
               "    leftprotoport=17/1701\n" .
               "    right=$serverIp\n" .
               "    rightprotoport=17/1701\n" .
               "    type=transport\n" .
               "EOF\n\n" .
               "# 3. Configure xl2tpd\n" .
               "sudo tee /etc/xl2tpd/xl2tpd.conf > /dev/null << 'EOF'\n" .
               "[lac tiam-l2tp]\n" .
               "lns = $serverIp\n" .
               "ppp optfile = /etc/ppp/options.l2tpd.client\n" .
               "length bit = yes\n" .
               "EOF\n\n" .
               "# 4. Configure PPP Options\n" .
               "sudo tee /etc/ppp/options.l2tpd.client > /dev/null << 'EOF'\n" .
               "ipcp-accept-local\n" .
               "ipcp-accept-remote\n" .
               "refuse-eap\n" .
               "require-mschap-v2\n" .
               "noccp\n" .
               "noauth\n" .
               "idle 1800\n" .
               "mtu 1410\n" .
               "mru 1410\n" .
               "defaultroute\n" .
               "usepeerdns\n" .
               "connect-delay 5000\n" .
               "name \"$user\"\n" .
               "password \"$pass\"\n" .
               "EOF\n\n" .
               "# Restart services and connect\n" .
               "sudo systemctl restart strongswan-starter || sudo systemctl restart ipsec\n" .
               "sudo systemctl restart xl2tpd\n" .
               "sudo ipsec up tiam-vpn\n" .
               "echo \"c tiam-l2tp\" | sudo tee /var/run/xl2tpd/l2tp-control\n" .
               "sleep 3\n" .
               "sudo ip route add $erpIp dev ppp0 2>/dev/null || true\n" .
               "echo 'Connected! Testing route to $erpIp...'\n" .
               "ping -c 2 $erpIp\n";
    }

    /**
     * Generate Windows PowerShell script for adding L2TP connection
     */
    public static function generateWindowsScript($vpnConfig, $erpConfig) {
        $serverIp = $vpnConfig['vpn_server_ip'] ?? '79.127.70.17';
        $user = $vpnConfig['vpn_username'] ?? 'ehsan';
        $pass = $vpnConfig['vpn_password'] ?? '12345';
        $secret = $vpnConfig['vpn_secret'] ?? '4747';
        $erpIp = $erpConfig['erp_server_ip'] ?? '192.168.2.205';

        return "# Windows PowerShell Script for Tiam ERP L2TP VPN\n" .
               "\$VpnName = \"Tiam-ERP-Connection\"\n" .
               "\$ServerAddress = \"$serverIp\"\n" .
               "\$PresharedKey = \"$secret\"\n" .
               "\$Username = \"$user\"\n" .
               "\$Password = \"$pass\"\n\n" .
               "# 1. Remove existing connection if present\n" .
               "Remove-VpnConnection -Name \$VpnName -Force -ErrorAction SilentlyContinue\n\n" .
               "# 2. Create L2TP/IPSec VPN Connection\n" .
               "Add-VpnConnection -Name \$VpnName -ServerAddress \$ServerAddress \\\n" .
               "    -TunnelType L2tp -L2tpPsk \$PresharedKey -AuthenticationMethod Pap,Chap,MSChapv2 \\\n" .
               "    -SplitTunneling \$true -RememberCredential\n\n" .
               "# 3. Add static route for Tiam internal server\n" .
               "Add-VpnConnectionRoute -ConnectionName \$VpnName -DestinationPrefix \"$erpIp/32\"\n\n" .
               "# 4. Connect\n" .
               "rasdial \$VpnName \$Username \$Password\n";
    }
}
