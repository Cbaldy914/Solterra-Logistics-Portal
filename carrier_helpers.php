<?php
/**
 * Carrier Helper Functions
 * Compliance status, Solterra abstraction, and display helpers
 */

/**
 * Get compliance status for a carrier based on COI, insurance, and authority fields.
 * Returns 'good', 'warning', or 'poor'.
 */
function get_carrier_compliance_status($carrier) {
    $coi_on_file = !empty($carrier['coi_on_file']);
    $coi_expiration = $carrier['coi_expiration_date'] ?? null;
    $insurance_met = !empty($carrier['insurance_minimum_met']);
    $authority = $carrier['authority_status'] ?? null;

    // Poor: COI expired or authority revoked
    if ($coi_on_file && $coi_expiration && strtotime($coi_expiration) < time()) {
        return 'poor';
    }
    if ($authority === 'revoked') {
        return 'poor';
    }

    // Warning: COI expiring within 30 days, or authority inactive
    if ($coi_on_file && $coi_expiration) {
        $days_until_expiry = (strtotime($coi_expiration) - time()) / 86400;
        if ($days_until_expiry <= 30) {
            return 'warning';
        }
    }
    if ($authority === 'inactive') {
        return 'warning';
    }

    // If COI is on file and not expiring, insurance met, authority active => good
    if ($coi_on_file && $insurance_met && $authority === 'active') {
        return 'good';
    }

    // If no compliance data filled in at all, return null (no badge)
    if (!$coi_on_file && !$insurance_met && $authority === null) {
        return null;
    }

    // Partial data filled in but nothing critical missing
    return 'warning';
}

/**
 * Get the ID of the "Solterra Solutions" carrier record.
 * Caches result for the duration of the request.
 */
function get_solterra_carrier_id($conn) {
    static $solterra_id = null;
    if ($solterra_id !== null) {
        return $solterra_id;
    }

    $stmt = $conn->prepare("SELECT id FROM carriers WHERE name = 'Solterra Solutions' AND is_solterra_managed = 1 LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $stmt->bind_result($solterra_id);
        if (!$stmt->fetch()) {
            $solterra_id = 0;
        }
        $stmt->close();
    }
    return $solterra_id;
}

/**
 * Get the display name for a carrier based on user role.
 * Customer roles see "Solterra Solutions" for any Solterra-managed carrier.
 * Admin roles see the real carrier name.
 */
function get_carrier_display_name($carrier, $role) {
    $is_customer_role = in_array($role, ['customer_admin', 'user'], true);
    if ($is_customer_role && !empty($carrier['is_solterra_managed'])) {
        return 'Solterra Solutions';
    }
    return $carrier['name'];
}

/**
 * Check if the current role is a customer-facing role (sees Solterra abstraction).
 */
function is_customer_role($role) {
    return in_array($role, ['customer_admin', 'user'], true);
}

/**
 * Check if the current role is an admin role (sees all carriers).
 */
function is_admin_role($role) {
    return in_array($role, ['admin', 'global_admin'], true);
}

/**
 * Get compliance badge HTML for a carrier.
 * Returns empty string if no compliance data exists.
 */
function get_compliance_badge_html($carrier) {
    $status = get_carrier_compliance_status($carrier);
    if ($status === null) {
        return '';
    }

    $colors = [
        'good' => ['bg' => '#d1fae5', 'color' => '#059669', 'dot' => '#059669', 'label' => 'Compliant'],
        'warning' => ['bg' => '#fef3c7', 'color' => '#92400e', 'dot' => '#fbb040', 'label' => 'Review Needed'],
        'poor' => ['bg' => '#fee2e2', 'color' => '#dc2626', 'dot' => '#dc2626', 'label' => 'Non-Compliant'],
    ];

    $c = $colors[$status] ?? $colors['warning'];
    $tooltip = build_compliance_tooltip($carrier);

    return '<span class="compliance-badge compliance-' . $status . '" title="' . htmlspecialchars($tooltip) . '" style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:0.75em;font-weight:600;background:' . $c['bg'] . ';color:' . $c['color'] . ';">'
         . '<span style="width:8px;height:8px;border-radius:50%;background:' . $c['dot'] . ';display:inline-block;"></span>'
         . $c['label'] . '</span>';
}

/**
 * Build tooltip text summarizing compliance details.
 */
function build_compliance_tooltip($carrier) {
    $parts = [];

    if (!empty($carrier['coi_on_file'])) {
        $exp = $carrier['coi_expiration_date'] ?? null;
        if ($exp) {
            $days = (strtotime($exp) - time()) / 86400;
            if ($days < 0) {
                $parts[] = 'COI: Expired ' . date('M j, Y', strtotime($exp));
            } elseif ($days <= 30) {
                $parts[] = 'COI: Expiring ' . date('M j, Y', strtotime($exp));
            } else {
                $parts[] = 'COI: Valid until ' . date('M j, Y', strtotime($exp));
            }
        } else {
            $parts[] = 'COI: On file (no expiration date)';
        }
    } else {
        $parts[] = 'COI: Not on file';
    }

    $parts[] = 'Insurance: ' . (!empty($carrier['insurance_minimum_met']) ? 'Met' : 'Not met');

    $authority = $carrier['authority_status'] ?? null;
    if ($authority) {
        $parts[] = 'Authority: ' . ucfirst($authority);
    }

    $rating = $carrier['fmcsa_safety_rating'] ?? null;
    if ($rating) {
        $parts[] = 'FMCSA: ' . ucfirst(str_replace('_', ' ', $rating));
    }

    return implode(' | ', $parts);
}
?>
