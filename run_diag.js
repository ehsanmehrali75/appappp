const { exec } = require('child_process');
exec('php test_diag.php', (err, stdout, stderr) => {
    if (err) {
        console.error('Execution Error:', err);
    }
    if (stderr) {
        console.error('STDERR:', stderr);
    }
    console.log(stdout);
});
