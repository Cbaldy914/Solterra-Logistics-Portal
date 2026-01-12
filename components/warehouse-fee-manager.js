/**
 * Warehouse Fee Manager - Dynamic fee management component
 * Provides reusable UI for managing warehouse cost items with multiple unit types
 */

class WarehouseFeeManager {
    constructor(options = {}) {
        this.containerId = options.containerId || 'feeManagerContainer';
        this.inputId = options.inputId || 'warehouseFeesInput';
        this.fees = options.initialFees || [];
        this.isPort = options.isPort || false;
        this.onUpdate = options.onUpdate || null;

        this.defaults = [
            { name: 'Entry Fee', amount: 0, unit: 'per_pallet', trigger: 'entry' },
            { name: 'Exit Fee', amount: 0, unit: 'per_pallet', trigger: 'exit' },
            { name: 'Monthly Storage', amount: 0, unit: 'per_pallet', trigger: 'monthly' }
        ];

        this.unitOptions = [
            { value: 'per_pallet', label: 'Per Pallet' },
            { value: 'per_truck', label: 'Per Truck' },
            { value: 'per_sqft', label: 'Per Sq. Ft.' },
            { value: 'flat', label: 'Flat Rate' }
        ];

        this.triggerOptions = [
            { value: 'entry', label: 'On Entry' },
            { value: 'exit', label: 'On Exit' },
            { value: 'monthly', label: 'Monthly' },
            { value: 'customs_clearance', label: 'Customs Clearance' },
            { value: 'drayage', label: 'Drayage' },
            { value: 'other', label: 'Other' }
        ];

        this.init();
    }

    init() {
        // If no fees provided, use defaults
        if (this.fees.length === 0) {
            this.fees = JSON.parse(JSON.stringify(this.defaults));
            // Add customs fee if port
            if (this.isPort) {
                this.fees.push({
                    name: 'Customs Clearance',
                    amount: 0,
                    unit: 'per_pallet',
                    trigger: 'customs_clearance'
                });
            }
        }
        this.render();
    }

    setIsPort(isPort) {
        this.isPort = isPort;
        // Check if customs fee exists
        const hasCustomsFee = this.fees.some(f => f.trigger === 'customs_clearance');

        if (isPort && !hasCustomsFee) {
            // Add customs fee
            this.fees.push({
                name: 'Customs Clearance',
                amount: 0,
                unit: 'per_pallet',
                trigger: 'customs_clearance'
            });
            this.render();
        }
    }

    addFee() {
        this.fees.push({
            name: 'New Fee',
            amount: 0,
            unit: 'per_pallet',
            trigger: 'other',
            displayOrder: this.fees.length
        });
        this.render();
    }

    removeFee(index) {
        if (this.fees.length > 1) {
            this.fees.splice(index, 1);
            this.render();
        }
    }

    updateFee(index, field, value) {
        if (this.fees[index]) {
            this.fees[index][field] = value;
            this.serialize();

            if (this.onUpdate) {
                this.onUpdate(this.fees);
            }
        }
    }

    updateFeeUnit(index, value) {
        const fee = this.fees[index];
        if (!fee) return;

        fee.unit = value;

        // Set default values for unit types that need them
        if (value === 'per_truck' && !fee.palletsPerTruck) {
            fee.palletsPerTruck = 26;
        }
        if (value === 'per_sqft' && !fee.sqftPerPallet) {
            fee.sqftPerPallet = 13.33;
        }

        this.render();
    }

    render() {
        const container = document.getElementById(this.containerId);
        if (!container) {
            console.error('Fee manager container not found:', this.containerId);
            return;
        }

        let html = `
            <div class="fee-table-container">
                <table class="fee-table">
                    <thead>
                        <tr>
                            <th>Fee Name</th>
                            <th>Amount ($)</th>
                            <th>Billing Unit</th>
                            <th>When Charged</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        this.fees.forEach((fee, index) => {
            html += this.renderRow(fee, index);
        });

        html += `
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn-add-fee" onclick="feeManager.addFee()">
                + Add Fee
            </button>
        `;

        container.innerHTML = html;
        this.serialize();
    }

    renderRow(fee, index) {
        const showPalletsPerTruck = fee.unit === 'per_truck';
        const showSqftPerPallet = fee.unit === 'per_sqft';

        // Build unit select options
        const unitOptionsHtml = this.unitOptions.map(o =>
            `<option value="${o.value}" ${fee.unit === o.value ? 'selected' : ''}>${o.label}</option>`
        ).join('');

        // Build trigger select options
        const triggerOptionsHtml = this.triggerOptions.map(o =>
            `<option value="${o.value}" ${fee.trigger === o.value ? 'selected' : ''}>${o.label}</option>`
        ).join('');

        // Extra parameter fields for certain unit types
        let extraFields = '';
        if (showPalletsPerTruck) {
            extraFields = `
                <div class="unit-param">
                    <label>Pallets/Truck:</label>
                    <input type="number" min="1" value="${fee.palletsPerTruck || 26}"
                           onchange="feeManager.updateFee(${index}, 'palletsPerTruck', parseInt(this.value) || 26)">
                </div>
            `;
        } else if (showSqftPerPallet) {
            extraFields = `
                <div class="unit-param">
                    <label>Sq.ft./Pallet:</label>
                    <input type="number" step="0.1" min="0.1" value="${fee.sqftPerPallet || 13.33}"
                           onchange="feeManager.updateFee(${index}, 'sqftPerPallet', parseFloat(this.value) || 13.33)">
                </div>
            `;
        }

        // Remove button (only show if more than 1 fee)
        const removeBtn = this.fees.length > 1
            ? `<button type="button" class="fee-row-remove" onclick="feeManager.removeFee(${index})">×</button>`
            : '';

        return `
            <tr>
                <td>
                    <input type="text" value="${this.escapeHtml(fee.name)}"
                           onchange="feeManager.updateFee(${index}, 'name', this.value)"
                           placeholder="Fee name">
                </td>
                <td>
                    <input type="number" step="0.01" min="0" class="amount-input"
                           value="${fee.amount || 0}"
                           onchange="feeManager.updateFee(${index}, 'amount', parseFloat(this.value) || 0)">
                </td>
                <td>
                    <select onchange="feeManager.updateFeeUnit(${index}, this.value)">
                        ${unitOptionsHtml}
                    </select>
                    ${extraFields}
                </td>
                <td>
                    <select onchange="feeManager.updateFee(${index}, 'trigger', this.value)">
                        ${triggerOptionsHtml}
                    </select>
                </td>
                <td>${removeBtn}</td>
            </tr>
        `;
    }

    serialize() {
        const input = document.getElementById(this.inputId);
        if (input) {
            input.value = JSON.stringify(this.fees);
        }
    }

    getFees() {
        return this.fees;
    }

    setFees(fees) {
        this.fees = fees;
        this.render();
    }

    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Global instance - will be initialized by page
let feeManager = null;
