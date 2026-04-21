/**
 * Warehouse Audit Offline Queue
 *
 * Thin IndexedDB wrapper for queueing scan + photo submissions so the live
 * session page is resilient to intermittent warehouse connectivity.
 *
 * Exposed API (window.AuditOffline):
 *   enqueueScan(payload)         -> Promise<void>    // tries immediate POST, falls back to IDB
 *   enqueuePhoto(fd)             -> Promise<void>
 *   drain()                      -> Promise<{drained, failed}>
 *   getStatus()                  -> Promise<{pending, failed}>
 *   onStatusChange(fn)           -> add listener for UI pill updates
 *
 * The server-side UNIQUE (session_id, client_uuid) constraint makes replay
 * idempotent, so we can safely retry the same payload.
 */
(function () {
    'use strict';

    var DB_NAME = 'solterra_audit_queue';
    var DB_VERSION = 1;
    var STORE_PENDING_SCANS  = 'pending_scans';
    var STORE_PENDING_PHOTOS = 'pending_photos';
    var STORE_FAILED         = 'failed_scans';
    var MAX_RETRIES = 10;

    var dbPromise = null;
    var drainTimer = null;
    var statusListeners = [];

    function openDb() {
        if (dbPromise) return dbPromise;
        dbPromise = new Promise(function (resolve, reject) {
            if (!window.indexedDB) {
                reject(new Error('IndexedDB not supported'));
                return;
            }
            var req = window.indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = function (e) {
                var db = e.target.result;
                if (!db.objectStoreNames.contains(STORE_PENDING_SCANS)) {
                    db.createObjectStore(STORE_PENDING_SCANS, { keyPath: 'id', autoIncrement: true });
                }
                if (!db.objectStoreNames.contains(STORE_PENDING_PHOTOS)) {
                    db.createObjectStore(STORE_PENDING_PHOTOS, { keyPath: 'id', autoIncrement: true });
                }
                if (!db.objectStoreNames.contains(STORE_FAILED)) {
                    db.createObjectStore(STORE_FAILED, { keyPath: 'id', autoIncrement: true });
                }
            };
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error); };
        });
        return dbPromise;
    }

    function tx(storeName, mode) {
        return openDb().then(function (db) {
            return db.transaction([storeName], mode).objectStore(storeName);
        });
    }

    function addToStore(storeName, record) {
        return tx(storeName, 'readwrite').then(function (store) {
            return new Promise(function (resolve, reject) {
                var r = store.add(record);
                r.onsuccess = function () { resolve(r.result); };
                r.onerror = function () { reject(r.error); };
            });
        });
    }

    function deleteFromStore(storeName, id) {
        return tx(storeName, 'readwrite').then(function (store) {
            return new Promise(function (resolve, reject) {
                var r = store.delete(id);
                r.onsuccess = function () { resolve(); };
                r.onerror = function () { reject(r.error); };
            });
        });
    }

    function updateInStore(storeName, record) {
        return tx(storeName, 'readwrite').then(function (store) {
            return new Promise(function (resolve, reject) {
                var r = store.put(record);
                r.onsuccess = function () { resolve(); };
                r.onerror = function () { reject(r.error); };
            });
        });
    }

    function listAll(storeName) {
        return tx(storeName, 'readonly').then(function (store) {
            return new Promise(function (resolve, reject) {
                var out = [];
                var req = store.openCursor();
                req.onsuccess = function (e) {
                    var cursor = e.target.result;
                    if (cursor) { out.push(cursor.value); cursor.continue(); }
                    else { resolve(out); }
                };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function countStore(storeName) {
        return tx(storeName, 'readonly').then(function (store) {
            return new Promise(function (resolve, reject) {
                var r = store.count();
                r.onsuccess = function () { resolve(r.result); };
                r.onerror = function () { reject(r.error); };
            });
        });
    }

    function notifyStatus() {
        getStatus().then(function (status) {
            statusListeners.forEach(function (fn) {
                try { fn(status); } catch (e) { /* ignore listener errors */ }
            });
        });
    }

    function postScanPayload(record) {
        var fd = new FormData();
        Object.keys(record.payload || {}).forEach(function (k) {
            var v = record.payload[k];
            if (v === null || v === undefined) return;
            if (typeof v === 'object') v = JSON.stringify(v);
            fd.append(k, v);
        });
        fd.append('retry_count', String(record.retries || 0));
        return fetch('api/audit/add_scan.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        }).then(function (r) {
            if (!r.ok) return r.json().then(function (j) { throw new Error(j.error || ('HTTP ' + r.status)); });
            return r.json();
        });
    }

    function enqueueScan(payload) {
        var record = {
            payload: payload,
            retries: 0,
            created_at: Date.now(),
        };
        // Try immediate POST first
        return postScanPayload(record)
            .then(function (data) {
                notifyStatus();
                return data;
            })
            .catch(function (err) {
                // Fall back: persist to IDB for retry
                return addToStore(STORE_PENDING_SCANS, record).then(function () {
                    notifyStatus();
                    throw err; // Surface to caller so UI can indicate "queued offline"
                });
            });
    }

    function enqueuePhoto(fd) {
        // Photos are heavier — try immediate POST; if fails, we'd need to store
        // the File blob which IndexedDB supports but we'll only persist the
        // metadata for the MVP so a failed photo requires manual re-attach.
        return fetch('api/audit/upload_photo.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        }).then(function (r) {
            if (!r.ok) return r.json().then(function (j) { throw new Error(j.error || ('HTTP ' + r.status)); });
            return r.json();
        });
    }

    function drain() {
        return listAll(STORE_PENDING_SCANS).then(function (records) {
            var drained = 0;
            var failed = 0;
            var chain = Promise.resolve();
            records.forEach(function (record) {
                chain = chain.then(function () {
                    return postScanPayload(record)
                        .then(function () {
                            drained++;
                            return deleteFromStore(STORE_PENDING_SCANS, record.id);
                        })
                        .catch(function (err) {
                            failed++;
                            record.retries = (record.retries || 0) + 1;
                            record.last_error = (err && err.message) || String(err);
                            if (record.retries >= MAX_RETRIES) {
                                return deleteFromStore(STORE_PENDING_SCANS, record.id)
                                    .then(function () { return addToStore(STORE_FAILED, record); });
                            }
                            return updateInStore(STORE_PENDING_SCANS, record);
                        });
                });
            });
            return chain.then(function () {
                notifyStatus();
                return { drained: drained, failed: failed };
            });
        });
    }

    function getStatus() {
        return Promise.all([
            countStore(STORE_PENDING_SCANS),
            countStore(STORE_FAILED),
        ]).then(function (counts) {
            return { pending: counts[0], failed: counts[1] };
        }).catch(function () {
            return { pending: 0, failed: 0 };
        });
    }

    function onStatusChange(fn) {
        statusListeners.push(fn);
        notifyStatus();
    }

    // Drain on load + online event + 30s interval
    window.addEventListener('online', function () { drain(); });
    document.addEventListener('DOMContentLoaded', function () {
        drain();
        drainTimer = setInterval(function () {
            if (navigator.onLine) drain();
        }, 30000);
    });

    window.AuditOffline = {
        enqueueScan: enqueueScan,
        enqueuePhoto: enqueuePhoto,
        drain: drain,
        getStatus: getStatus,
        onStatusChange: onStatusChange,
    };
})();
