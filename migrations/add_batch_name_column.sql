-- Migration: Add batch_name column to modules table
-- Run this once against the database

ALTER TABLE modules ADD COLUMN batch_name VARCHAR(255) DEFAULT NULL AFTER id;
