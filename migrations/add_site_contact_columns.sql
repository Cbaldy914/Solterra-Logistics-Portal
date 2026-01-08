-- Migration: Add site contact columns to projects table
-- Date: 2026-01-08
-- Description: Adds site contact name, email, and phone columns for project site information

ALTER TABLE `projects`
ADD COLUMN `site_contact_name` VARCHAR(255) DEFAULT NULL AFTER `additional_notes`,
ADD COLUMN `site_contact_email` VARCHAR(255) DEFAULT NULL AFTER `site_contact_name`,
ADD COLUMN `site_contact_phone` VARCHAR(50) DEFAULT NULL AFTER `site_contact_email`;
