<?php
/**
 * Milestone Detail Table Component
 *
 * Displays detailed milestone status for a project, grouped by module batch.
 * Shows each configured milestone and its triggered/pending status.
 *
 * Usage:
 * $project_id = 123;
 * include 'components/milestone_detail_table.php';
 *
 * Required variables:
 * - $project_id: int
 * - $conn: mysqli connection
 *
 * Optional variables:
 * - $section_title: string (default: 'Module Payment Milestones')
 * - $collapsible: bool (default: false)
 */

// Ensure required variables and connection is still open
if (!isset($project_id)) {
    return;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    if (!function_exists('getDBConnection')) {
        require_once __DIR__ . '/../../config.php';
    }
    if (function_exists('getDBConnection')) {
        $conn = getDBConnection();
    }
}

$connection_ok = false;
if ($conn instanceof mysqli) {
    try {
        $connection_ok = $conn->ping();
    } catch (Throwable $e) {
        $connection_ok = false;
    }
}

if (!$connection_ok) {
    if (!function_exists('getDBConnection')) {
        require_once __DIR__ . '/../../config.php';
    }
    if (function_exists('getDBConnection')) {
        $conn = getDBConnection();
        if ($conn instanceof mysqli) {
            try {
                $connection_ok = $conn->ping();
            } catch (Throwable $e) {
                $connection_ok = false;
            }
        }
    }
}

if (!$connection_ok) {
    return;
}

// Set defaults
$section_title = $section_title ?? 'Module Payment Milestones';
$collapsible = $collapsible ?? false;
$compact_batch_header = $compact_batch_header ?? false;

// Get completion status which includes batch info
require_once __DIR__ . '/../milestone_helpers.php';
$completion_status = get_milestone_completion_status($project_id, $conn);

// If no milestone data, don't show the section
if ($completion_status['total_contract_value'] <= 0) {
    return;
}

// Trigger event labels
$trigger_labels = [
    'po_execution' => 'PO Execution',
    'shipping' => 'Shipping',
    'customs_cleared' => 'Customs Clearance',
    'project_delivery' => 'Project Delivery'
];

// Get detailed milestone info for each batch
$batches_with_milestones = [];

foreach ($completion_status['batches'] as $batch) {
    $module_id = $batch['module_id'];

    // Get configured milestones
    $milestones = get_module_milestones($module_id, $conn);

    // Get triggered instances for this batch
    $stmt = $conn->prepare("
        SELECT dmi.*, mbm.trigger_event, mbm.milestone_name, d.bol_number, d.status_of_delivery, d.origin_type
        FROM delivery_milestone_instances dmi
        JOIN module_batch_milestones mbm ON dmi.milestone_id = mbm.id
        LEFT JOIN deliveries d ON dmi.delivery_id = d.id
        WHERE mbm.module_id = ?
    ");

    $triggered_map = [];
    $triggered_details = [];
    if ($stmt) {
        $stmt->bind_param("i", $module_id);
        $stmt->execute();
        $triggered = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($triggered as $t) {
            $origin_type = strtolower(trim((string)($t['origin_type'] ?? '')));
            if (($t['trigger_event'] ?? '') === 'shipping' && $origin_type === 'warehouse') {
                continue;
            }
            $milestone_id = $t['milestone_id'];
            if (!isset($triggered_map[$milestone_id])) {
                $triggered_map[$milestone_id] = [
                    'amount' => 0.0,
                    'count' => 0,
                    'latest_triggered' => null
                ];
            }
            $triggered_map[$milestone_id]['amount'] += floatval($t['payment_amount']);
            $triggered_map[$milestone_id]['count'] += 1;
            if (!empty($t['triggered_at'])) {
                $current_latest = $triggered_map[$milestone_id]['latest_triggered'];
                if (!$current_latest || strtotime($t['triggered_at']) > strtotime($current_latest)) {
                    $triggered_map[$milestone_id]['latest_triggered'] = $t['triggered_at'];
                }
            }

            if (!empty($t['delivery_id'])) {
                if (!isset($triggered_details[$milestone_id])) {
                    $triggered_details[$milestone_id] = [];
                }
                $detail_parts = [];
                if (!empty($t['bol_number'])) {
                    $detail_parts[] = 'BOL ' . $t['bol_number'];
                } else {
                    $detail_parts[] = 'Delivery #' . $t['delivery_id'];
                }
                if (!empty($t['module_quantity'])) {
                    $detail_parts[] = number_format((int)$t['module_quantity']) . ' modules';
                }
                if (!empty($t['wattage'])) {
                    $detail_parts[] = (int)$t['wattage'] . 'W';
                }
                if (!empty($t['triggered_at'])) {
                    $detail_parts[] = date('M j, Y', strtotime($t['triggered_at']));
                }

                $triggered_details[$milestone_id][] = [
                    'label' => implode(' • ', $detail_parts),
                    'amount' => floatval($t['payment_amount']),
                    'triggered_at' => $t['triggered_at']
                ];
            }
        }
    }

    // Build milestone status array
    $milestone_statuses = [];
    $batch_triggered = 0;
    $batch_total = 0;

    foreach ($milestones as $m) {
        $percentage = floatval($m['percentage']);
        $total_amount = $batch['batch_value'] * ($percentage / 100);
        $triggered_amount = $triggered_map[$m['id']]['amount'] ?? 0.0;
        $triggered_count = $triggered_map[$m['id']]['count'] ?? 0;
        $latest_triggered = $triggered_map[$m['id']]['latest_triggered'] ?? null;
        $triggered_percent = $total_amount > 0 ? round(($triggered_amount / $total_amount) * 100, 1) : 0.0;
        $is_fully_triggered = $total_amount > 0 ? ($triggered_amount >= ($total_amount - 0.01)) : false;

        $batch_triggered += $triggered_amount;
        $batch_total += $total_amount;

        $details = $triggered_details[$m['id']] ?? [];
        if (!empty($details)) {
            usort($details, function($a, $b) {
                return strtotime($b['triggered_at'] ?? '1970-01-01') <=> strtotime($a['triggered_at'] ?? '1970-01-01');
            });
        }

        $milestone_statuses[] = [
            'id' => $m['id'],
            'trigger_event' => $m['trigger_event'],
            'name' => $m['milestone_name'] ?? $trigger_labels[$m['trigger_event']] ?? $m['trigger_event'],
            'percentage' => $percentage,
            'total_amount' => $total_amount,
            'triggered_amount' => $triggered_amount,
            'triggered_percent' => $triggered_percent,
            'triggered_count' => $triggered_count,
            'is_triggered' => $is_fully_triggered,
            'triggered_at' => $latest_triggered,
            'details' => $details
        ];
    }

    $batches_with_milestones[] = [
        'module_id' => $module_id,
        'vendor_name' => $batch['vendor_name'],
        'batch_value' => $batch['batch_value'],
        'total_quantity' => $batch['total_quantity'],
        'milestones' => $milestone_statuses,
        'triggered_total' => $batch_triggered,
        'batch_total' => $batch_total
    ];
}
?>

<div class="milestone-detail-section" style="margin-bottom: 24px;">
    <div style="
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        border: 1px solid #e9ecef;
        border-radius: 12px;
        overflow: hidden;
    ">
        <!-- Section Header -->
        <div style="
            padding: 16px 20px;
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        " <?php if ($collapsible): ?>onclick="toggleMilestoneDetails()" style="cursor: pointer;"<?php endif; ?>>
            <h4 style="margin: 0; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <span>💰</span>
                <?php echo htmlspecialchars($section_title); ?>
            </h4>
            <div style="display: flex; align-items: center; gap: 16px;">
                <span style="font-size: 14px; opacity: 0.9;">
                    $<?php echo number_format($completion_status['total_triggered'], 0); ?> / $<?php echo number_format($completion_status['total_contract_value'], 0); ?>
                    (<?php echo number_format($completion_status['completion_percent'], 0); ?>%)
                </span>
                <?php if ($collapsible): ?>
                <span id="milestone-toggle-icon" style="font-size: 18px;">▼</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Section Body -->
        <div id="milestone-detail-body" style="padding: 20px;">
            <?php foreach ($batches_with_milestones as $index => $batch): ?>
            <div style="
                <?php if ($index > 0): ?>margin-top: 20px; padding-top: 20px; border-top: 1px solid #e9ecef;<?php endif; ?>
            ">
                <!-- Batch Header -->
                <div style="margin-bottom: 12px;">
                    <?php if ($compact_batch_header): ?>
                    <div style="font-weight: 600; color: #293E4C; font-size: 13px;">
                        <?php echo htmlspecialchars($batch['vendor_name'] ?? 'Module Batch'); ?>
                        <span style="font-weight: 500; color: #6c757d;">
                            - Contract Value: $<?php echo number_format($batch['batch_value'], 2); ?>
                            • <?php echo number_format($batch['total_quantity']); ?> modules
                        </span>
                    </div>
                    <?php else: ?>
                    <div style="font-weight: 600; color: #293E4C; font-size: 14px;">
                        <?php echo htmlspecialchars($batch['vendor_name'] ?? 'Module Batch'); ?>
                    </div>
                    <div style="font-size: 12px; color: #6c757d; margin-top: 2px;">
                        Contract Value: $<?php echo number_format($batch['batch_value'], 2); ?>
                        • <?php echo number_format($batch['total_quantity']); ?> modules
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Milestones Table -->
                <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                    <thead>
                        <tr style="background: #293E4C;">
                            <th style="padding: 8px 12px; text-align: left; font-weight: 500; color: #ffffff; border-bottom: 1px solid #e9ecef;">Milestone</th>
                            <th style="padding: 8px 12px; text-align: left; font-weight: 500; color: #ffffff; border-bottom: 1px solid #e9ecef;">Percentage</th>
                            <th style="padding: 8px 12px; text-align: left; font-weight: 500; color: #ffffff; border-bottom: 1px solid #e9ecef;">Amount</th>
                            <th style="padding: 8px 12px; text-align: left; font-weight: 500; color: #ffffff; border-bottom: 1px solid #e9ecef;">Status</th>
                            <th style="padding: 8px 12px; text-align: left; font-weight: 500; color: #ffffff; border-bottom: 1px solid #e9ecef;">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batch['milestones'] as $milestone): ?>
                        <tr style="border-bottom: 1px solid #f1f3f5;">
                            <td style="padding: 10px 12px; color: #495057;">
                                <?php echo htmlspecialchars($milestone['name']); ?>
                            </td>
                            <td style="padding: 10px 12px; color: #6c757d;">
                                <?php echo number_format($milestone['percentage'], 1); ?>%
                            </td>
                            <td style="padding: 10px 12px; font-weight: 500; color: #293E4C;">
                                $<?php echo number_format($milestone['triggered_amount'], 2); ?>
                                <div style="font-size: 11px; color: #6c757d;">
                                    of $<?php echo number_format($milestone['total_amount'], 2); ?>
                                </div>
                                <?php if (!empty($milestone['details'])): ?>
                                <div style="margin-top: 6px; font-size: 11px;">
                                    <a href="view_project.php?project_id=<?php echo (int)$project_id; ?>" style="color: #17a2b8; text-decoration: none;">
                                        View deliveries (<?php echo count($milestone['details']); ?>)
                                    </a>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 10px 12px;">
                                <?php if ($milestone['triggered_amount'] <= 0): ?>
                                <span style="
                                    display: inline-flex;
                                    align-items: center;
                                    gap: 4px;
                                    padding: 4px 10px;
                                    background: #fef3c7;
                                    color: #d97706;
                                    border-radius: 12px;
                                    font-size: 11px;
                                    font-weight: 500;
                                ">
                                    <span>○</span> Pending
                                </span>
                                <?php elseif ($milestone['is_triggered']): ?>
                                <span style="
                                    display: inline-flex;
                                    align-items: center;
                                    gap: 4px;
                                    padding: 4px 10px;
                                    background: #d4edda;
                                    color: #155724;
                                    border-radius: 12px;
                                    font-size: 11px;
                                    font-weight: 500;
                                ">
                                    <span style="color: #28a745;">✓</span> Triggered
                                </span>
                                <?php else: ?>
                                <span style="
                                    display: inline-flex;
                                    align-items: center;
                                    gap: 4px;
                                    padding: 4px 10px;
                                    background: #e8f4f6;
                                    color: #488C9A;
                                    border-radius: 12px;
                                    font-size: 11px;
                                    font-weight: 500;
                                ">
                                    ↻ <?php echo number_format($milestone['triggered_percent'], 1); ?>% Triggered
                                </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 10px 12px; color: #6c757d; font-size: 12px;">
                                <?php echo $milestone['triggered_at'] ? date('M j, Y', strtotime($milestone['triggered_at'])) : '—'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background: #f8f9fa;">
                            <td colspan="2" style="padding: 10px 12px; font-weight: 600; color: #293E4C;">
                                Total Triggered
                            </td>
                            <td style="padding: 10px 12px; font-weight: 600; color: #28a745;">
                                $<?php echo number_format($batch['triggered_total'], 2); ?>
                            </td>
                            <td colspan="2" style="padding: 10px 12px; font-size: 12px; color: #6c757d;">
                                <?php
                                $batch_percent = $batch['batch_value'] > 0
                                    ? round(($batch['triggered_total'] / $batch['batch_value']) * 100, 0)
                                    : 0;
                                echo $batch_percent . '% of contract value';
                                ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($collapsible): ?>
<script>
function toggleMilestoneDetails() {
    const body = document.getElementById('milestone-detail-body');
    const icon = document.getElementById('milestone-toggle-icon');
    if (body.style.display === 'none') {
        body.style.display = 'block';
        icon.textContent = '▼';
    } else {
        body.style.display = 'none';
        icon.textContent = '▶';
    }
}
</script>
<?php endif; ?>
