<?php
/**
 * Milestone Timeline Table Component
 *
 * Displays a timeline of payment events including milestones and optionally logistics costs.
 *
 * Usage:
 * $milestone_timeline = get_milestone_payment_timeline($project_id, $conn);
 * include 'components/milestone_timeline_table.php';
 *
 * Required variables:
 * - $milestone_timeline: array from get_milestone_payment_timeline()
 *
 * Optional variables:
 * - $logistics_costs: array of logistics cost events to merge into timeline
 *   Format: [['date' => 'Y-m-d', 'event_type' => 'freight|warehousing|accessorial', 'description' => '', 'amount' => 0], ...]
 * - $table_title: string (default: 'Payment Timeline')
 * - $show_cumulative: bool (default: true)
 * - $max_rows: int (default: 10, 0 for unlimited)
 * - $show_view_all: bool (default: true) - Show "View All" link when truncated
 */

// Set defaults
$table_title = $table_title ?? 'Payment Timeline';
$show_cumulative = $show_cumulative ?? true;
$max_rows = $max_rows ?? 10;
$show_view_all = $show_view_all ?? true;
$logistics_costs = $logistics_costs ?? [];

// Merge milestone timeline with logistics costs
$all_events = $milestone_timeline ?? [];

// Add logistics costs to timeline
foreach ($logistics_costs as $cost) {
    $all_events[] = [
        'date' => $cost['date'] ?? null,
        'event_type' => $cost['event_type'] ?? 'other',
        'trigger_event' => null,
        'description' => $cost['description'] ?? '',
        'amount' => floatval($cost['amount'] ?? 0),
        'cumulative' => 0, // Will recalculate below
        'delivery_id' => $cost['delivery_id'] ?? null,
        'module_batch_id' => null
    ];
}

// Sort by date
usort($all_events, function($a, $b) {
    $date_a = strtotime($a['date'] ?? '1970-01-01');
    $date_b = strtotime($b['date'] ?? '1970-01-01');
    return $date_a - $date_b;
});

// Recalculate cumulative totals
$cumulative = 0;
foreach ($all_events as &$event) {
    $cumulative += floatval($event['amount']);
    $event['cumulative'] = $cumulative;
}
unset($event);

// Check if we have any events
if (empty($all_events)) {
    return; // Nothing to show
}

// Determine if we need to truncate
$total_events = count($all_events);
$is_truncated = $max_rows > 0 && $total_events > $max_rows;
$display_events = $is_truncated ? array_slice($all_events, 0, $max_rows) : $all_events;

// Helper function to get event type badge
function get_event_badge($event) {
    $type = $event['event_type'] ?? 'other';
    $trigger = $event['trigger_event'] ?? null;

    $badges = [
        'milestone' => [
            'po_execution' => ['PO', '#6f42c1', '#f3e8ff'],
            'shipping' => ['Ship', '#0dcaf0', '#e7f9fd'],
            'customs_cleared' => ['Customs', '#fd7e14', '#fff3e6'],
            'project_delivery' => ['Delivery', '#28a745', '#e8f5e9']
        ],
        'freight' => ['Freight', '#17a2b8', '#e3f2fd'],
        'warehousing' => ['Storage', '#6c757d', '#f8f9fa'],
        'accessorial' => ['Accessorial', '#495057', '#f1f3f5']
    ];

    if ($type === 'milestone' && isset($badges['milestone'][$trigger])) {
        return $badges['milestone'][$trigger];
    } elseif (isset($badges[$type])) {
        return $badges[$type];
    }
    return ['Other', '#6c757d', '#f8f9fa'];
}
?>

<div class="milestone-timeline-table">
    <?php if ($table_title): ?>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
        <h5 style="margin: 0; font-size: 14px; font-weight: 600; color: #293E4C;">
            <?php echo htmlspecialchars($table_title); ?>
        </h5>
        <?php if ($is_truncated && $show_view_all): ?>
        <a href="#" onclick="toggleFullTimeline(); return false;" style="font-size: 12px; color: #17a2b8; text-decoration: none;">
            View All (<?php echo $total_events; ?>)
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <thead>
                <tr style="background: #f8f9fa; border-bottom: 2px solid #e9ecef;">
                    <th style="padding: 10px 12px; text-align: left; font-weight: 600; color: #495057;">Date</th>
                    <th style="padding: 10px 12px; text-align: left; font-weight: 600; color: #495057;">Event</th>
                    <th style="padding: 10px 12px; text-align: left; font-weight: 600; color: #495057;">Description</th>
                    <th style="padding: 10px 12px; text-align: right; font-weight: 600; color: #495057;">Amount</th>
                    <?php if ($show_cumulative): ?>
                    <th style="padding: 10px 12px; text-align: right; font-weight: 600; color: #495057;">Cumulative</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($display_events as $event):
                    $badge = get_event_badge($event);
                    $badge_text = $badge[0];
                    $badge_color = $badge[1];
                    $badge_bg = $badge[2];
                ?>
                <tr style="border-bottom: 1px solid #e9ecef;">
                    <td style="padding: 10px 12px; color: #495057; white-space: nowrap;">
                        <?php echo $event['date'] ? date('M j, Y', strtotime($event['date'])) : '—'; ?>
                    </td>
                    <td style="padding: 10px 12px;">
                        <span style="
                            display: inline-block;
                            padding: 3px 8px;
                            border-radius: 4px;
                            font-size: 11px;
                            font-weight: 500;
                            background: <?php echo $badge_bg; ?>;
                            color: <?php echo $badge_color; ?>;
                        "><?php echo htmlspecialchars($badge_text); ?></span>
                    </td>
                    <td style="padding: 10px 12px; color: #495057;">
                        <?php echo htmlspecialchars($event['description'] ?? ''); ?>
                    </td>
                    <td style="padding: 10px 12px; text-align: right; font-weight: 500; color: #293E4C;">
                        $<?php echo number_format($event['amount'], 2); ?>
                    </td>
                    <?php if ($show_cumulative): ?>
                    <td style="padding: 10px 12px; text-align: right; color: #17a2b8; font-weight: 500;">
                        $<?php echo number_format($event['cumulative'], 2); ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>

                <?php if ($is_truncated): ?>
                <tr id="timeline-truncated-row" style="border-bottom: 1px solid #e9ecef; background: #fafafa;">
                    <td colspan="<?php echo $show_cumulative ? 5 : 4; ?>" style="padding: 10px 12px; text-align: center; color: #6c757d; font-style: italic;">
                        + <?php echo $total_events - $max_rows; ?> more events
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background: #f8f9fa; border-top: 2px solid #e9ecef;">
                    <td colspan="3" style="padding: 10px 12px; font-weight: 600; color: #293E4C;">Total</td>
                    <td style="padding: 10px 12px; text-align: right; font-weight: 600; color: #293E4C;">
                        $<?php echo number_format($cumulative, 2); ?>
                    </td>
                    <?php if ($show_cumulative): ?>
                    <td></td>
                    <?php endif; ?>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php if ($is_truncated): ?>
<script>
function toggleFullTimeline() {
    // This can be enhanced to show a modal or expand the table
    alert('Full timeline view coming soon!');
}
</script>
<?php endif; ?>
