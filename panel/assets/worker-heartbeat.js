/* One lightweight dispatch shared by extraction and the global panel timer. */
(function () {
    'use strict';
    let pending = null;
    let nextAttempt = 0;
    let failures = 0;
    let previous = {status: 'idle'};
    window.workerHeartbeat = function () {
        if (pending) return pending;
        if (document.hidden || Date.now() < nextAttempt) return Promise.resolve(previous);
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 10000);
        pending = fetch('cron.php?trigger=1&ajax=1&background=1', {
            method: 'POST', signal: controller.signal
        }).then(async response => {
            if (!response.ok) throw new Error('Worker dispatch HTTP ' + response.status);
            const result = await response.json();
            if (!['accepted', 'busy', 'cron_required'].includes(result.status)) throw new Error('Invalid worker acknowledgement');
            failures = 0;
            nextAttempt = Date.now() + 60000;
            previous = result;
            return result;
        }).catch(error => {
            failures++;
            nextAttempt = Date.now() + Math.min(300000, 30000 * Math.pow(2, failures - 1));
            previous = {status: 'unavailable'};
            // Network delays do not establish whether the worker itself stopped.
            return previous;
        }).finally(() => {
            clearTimeout(timer);
            pending = null;
        });
        return pending;
    };
}());
