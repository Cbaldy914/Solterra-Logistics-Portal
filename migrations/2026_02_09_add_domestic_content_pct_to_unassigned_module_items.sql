-- Add optional domestic content tracking per module wattage row
ALTER TABLE unassigned_module_items
    ADD COLUMN domestic_content_pct DECIMAL(5,2) NULL AFTER quantity;
