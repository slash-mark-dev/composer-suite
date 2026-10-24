<?php

declare(strict_types=1);

/**
 * Generates the "Composer Suite" section for the Digital Ecosystem page on slashmark.in.
 *   php website/build.php   → website/composer-suite.html (paste-in section) + website/preview.html
 */

$tools = [
    // [title, description, command example, category]
    ['Packagist Package Search', 'Search PHP packages from Packagist with filters and instant results.', 'sm-composer search jwt --min-downloads 1M', 'discover'],
    ['Version Checker', 'Check latest and installed versions of Composer packages.', 'sm-composer version', 'insight'],
    ['Dependency Tree Viewer', 'Visualize dependency hierarchy from composer.json.', 'sm-composer tree --depth 3', 'project'],
    ['Package Info Inspector', 'View metadata like author, license, version, description.', 'sm-composer info monolog/monolog', 'insight'],
    ['Reverse Dependencies Finder', 'Find packages depending on a selected package.', 'sm-composer dependents psr/log', 'discover'],
    ['Download Stats Analyzer', 'Analyze package popularity via download counts.', 'sm-composer stats guzzlehttp/guzzle', 'discover'],
    ['composer.json Viewer', 'Parse and display composer.json in readable format.', 'sm-composer json --validate', 'project'],
    ['Dependency Conflict Checker', 'Detect version conflicts in dependencies.', 'sm-composer conflicts --with vendor/pkg', 'project'],
    ['Security Advisory Checker', 'Check vulnerabilities using FriendsOfPHP security advisories.', 'sm-composer security --no-dev', 'security'],
    ['Package Compare Tool', 'Compare two packages (versions, dependencies, metadata).', 'sm-composer compare guzzlehttp/guzzle symfony/http-client', 'insight'],
    ['Latest Updates Tracker', 'Track newly released or recently updated packages.', 'sm-composer updates --project', 'discover'],
    ['Deprecated Package Finder', 'Identify abandoned or deprecated packages.', 'sm-composer abandoned', 'security'],
    ['Maintainer Info Viewer', 'Display maintainers and contributors.', 'sm-composer maintainers laravel/framework -c', 'insight'],
    ['Namespace Explorer', 'Browse packages by vendor namespace.', 'sm-composer vendor spatie', 'discover'],
    ['Autoload Analyzer', 'Analyze PSR-4 / PSR-0 autoload configurations.', 'sm-composer autoload', 'project'],
    ['PHP Version Compatibility Checker', 'Verify compatibility with PHP versions.', 'sm-composer php 8.4', 'security'],
    ['License Compliance Checker', 'Analyze licenses for compliance risks.', 'sm-composer license --no-dev', 'security'],
    ['Package Size Analyzer', 'Estimate package size and dependency weight.', 'sm-composer size', 'project'],
    ['Offline Package Exporter', 'Export metadata for offline use.', 'sm-composer export --format html', 'workflow'],
    ['Trending Packages Dashboard', 'Discover trending and popular packages.', 'sm-composer trending --sort growth', 'discover'],
    ['Composer Command Generator', 'Generate composer require/install commands.', 'sm-composer cmd require monolog/monolog', 'workflow'],
    ['Dependency Update Checker', 'Check for outdated dependencies.', 'sm-composer outdated --commands', 'project'],
    ['Documentation Viewer', 'Display package documentation.', 'sm-composer docs guzzlehttp/guzzle', 'insight'],
    ['Source Code Explorer', 'Browse GitHub/source repository.', 'sm-composer source monolog/monolog', 'insight'],
    ['Broken Link Checker', 'Detect invalid repository/docs links.', 'sm-composer links --project', 'security'],
    ['Package Health Score', 'Score packages based on maintenance/activity.', 'sm-composer health --project', 'insight'],
    ['Multi-Package Dashboard', 'Monitor multiple packages in one view.', 'sm-composer dashboard --project', 'workflow'],
    ['Version Constraint Tester', 'Test composer version constraints (^, ~, etc.).', 'sm-composer constraint "^1.2" 1.9.0 2.0.0', 'project'],
    ['Lock File Analyzer', 'Analyze composer.lock dependencies.', 'sm-composer lock', 'project'],
    ['Installation Troubleshooter', 'Diagnose composer install/update issues.', 'sm-composer doctor', 'workflow'],
    ['Changelog Viewer', 'Display release notes and changelogs.', 'sm-composer changelog laravel/framework', 'insight'],
    ['Framework Compatibility Checker', 'Check compatibility with Laravel, Symfony, etc.', 'sm-composer framework --laravel 12', 'security'],
];
$cats = ['all' => 'All 32', 'discover' => 'Discover', 'insight' => 'Package insight', 'project' => 'Project analysis', 'security' => 'Security & compliance', 'workflow' => 'Workflow'];
$catLabel = ['discover' => 'Discover', 'insight' => 'Insight', 'project' => 'Project', 'security' => 'Security', 'workflow' => 'Workflow'];
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);

$css = <<<'CSS'
<style>
.smc{--smc-bg:#020617;--smc-card:#0b1224;--smc-line:#1e293b;--smc-fg:#e2e8f0;--smc-mut:#94a3b8;--smc-acc:#8b5cf6;--smc-acc2:#22d3ee;--smc-code:#030712;color:var(--smc-fg);font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;line-height:1.5}
.smc *{box-sizing:border-box}
.smc-hero{text-align:center;max-width:820px;margin:0 auto 28px}
.smc-eyebrow{display:inline-block;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--smc-acc2);border:1px solid var(--smc-line);border-radius:99px;padding:4px 12px;margin-bottom:14px}
.smc-hero h2{font-size:clamp(28px,4vw,40px);margin:0 0 10px;line-height:1.15}
.smc-hero h2 span{background:linear-gradient(90deg,var(--smc-acc),var(--smc-acc2));-webkit-background-clip:text;background-clip:text;color:transparent}
.smc-hero p{color:var(--smc-mut);font-size:17px;margin:0 0 18px}
.smc-install{display:flex;align-items:center;gap:10px;max-width:560px;margin:0 auto 14px;background:var(--smc-code);border:1px solid var(--smc-line);border-radius:12px;padding:10px 12px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:14px;text-align:left}
.smc-install code{flex:1;overflow-x:auto;white-space:nowrap;color:#a5f3fc}
.smc-pills{display:flex;flex-wrap:wrap;justify-content:center;gap:8px}
.smc-pill{font-size:12px;color:var(--smc-mut);border:1px solid var(--smc-line);border-radius:99px;padding:3px 10px}
.smc-tabs{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin:22px 0 20px}
.smc-tab{background:transparent;border:1px solid var(--smc-line);color:var(--smc-mut);border-radius:99px;padding:6px 14px;font-size:13px;cursor:pointer;font-family:inherit}
.smc-tab[aria-pressed="true"],.smc-tab:hover{color:#fff;border-color:var(--smc-acc);background:rgba(139,92,246,.15)}
.smc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
.smc-card{background:var(--smc-card);border:1px solid var(--smc-line);border-radius:14px;padding:16px;display:flex;flex-direction:column;gap:10px;transition:border-color .2s,transform .2s;min-width:0}
.smc-card:hover{border-color:var(--smc-acc);transform:translateY(-2px)}
.smc-card[hidden]{display:none}
.smc-top{display:flex;justify-content:space-between;align-items:center;gap:8px}
.smc-num{font-size:12px;color:var(--smc-mut);font-family:ui-monospace,Menlo,Consolas,monospace}
.smc-cat{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--smc-acc2)}
.smc-card h4{margin:0;font-size:16px;line-height:1.3}
.smc-card p{margin:0;color:var(--smc-mut);font-size:14px;flex:1}
.smc-code{display:flex;align-items:center;gap:8px;background:var(--smc-code);border:1px solid var(--smc-line);border-radius:8px;padding:7px 8px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px;min-width:0}
.smc-code .p{color:var(--smc-acc)}
.smc-code code{flex:1;overflow-x:auto;white-space:nowrap;color:#e2e8f0;scrollbar-width:thin}
.smc-copy{flex:none;background:#1e293b;color:#cbd5e1;border:0;border-radius:6px;font-size:11px;padding:4px 8px;cursor:pointer;font-family:inherit}
.smc-copy:hover{background:var(--smc-acc);color:#fff}
.smc-foot{text-align:center;margin-top:18px;color:var(--smc-mut);font-size:14px}
.smc-foot a{color:var(--smc-acc2)}
.smc-cta{margin-top:34px;border:1px solid var(--smc-line);border-radius:18px;padding:28px 22px;background:radial-gradient(circle at 15% 0%,rgba(139,92,246,.22),transparent 55%),radial-gradient(circle at 90% 100%,rgba(34,211,238,.14),transparent 50%),var(--smc-card);display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:18px}
.smc-cta h3{margin:0 0 6px;font-size:22px}
.smc-cta p{margin:0;color:var(--smc-mut);max-width:560px}
.smc-btn{display:inline-block;background:linear-gradient(90deg,var(--smc-acc),#6366f1);color:#fff;text-decoration:none;font-weight:600;border-radius:10px;padding:11px 20px;white-space:nowrap}
.smc-btn:hover{filter:brightness(1.1)}
@media (max-width:520px){.smc-grid{grid-template-columns:1fr}.smc-cta{flex-direction:column;align-items:flex-start}}
</style>
CSS;

$js = <<<'JS'
<script>
(function(){var root=document.getElementById('composer-suite');if(!root)return;
root.querySelectorAll('.smc-tab').forEach(function(t){t.addEventListener('click',function(){var c=t.getAttribute('data-cat');
root.querySelectorAll('.smc-tab').forEach(function(x){x.setAttribute('aria-pressed',x===t?'true':'false')});
root.querySelectorAll('.smc-card').forEach(function(card){card.hidden=!(c==='all'||card.getAttribute('data-cat')===c)});});});
root.querySelectorAll('.smc-copy').forEach(function(b){b.addEventListener('click',function(){var t=b.parentNode.querySelector('code').textContent;
var done=function(){var o=b.textContent;b.textContent='Copied';setTimeout(function(){b.textContent=o},1200)};
if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(done)}else{var a=document.createElement('textarea');a.value=t;document.body.appendChild(a);a.select();try{document.execCommand('copy');done()}catch(e){}a.remove()}});});})();
</script>
JS;

$cards = '';
foreach ($tools as $i => [$title, $desc, $ex, $cat]) {
    $cards .= '      <article class="smc-card" data-cat="' . $cat . '">' . "\n"
        . '        <div class="smc-top"><span class="smc-num">' . sprintf('%02d', $i + 1) . '</span><span class="smc-cat">' . $catLabel[$cat] . '</span></div>' . "\n"
        . '        <h4>' . $e($title) . '</h4>' . "\n"
        . '        <p>' . $e($desc) . '</p>' . "\n"
        . '        <div class="smc-code"><span class="p">$</span><code>' . $e($ex) . '</code><button class="smc-copy" type="button">Copy</button></div>' . "\n"
        . "      </article>\n";
}
$tabs = '';
foreach ($cats as $k => $label) {
    $tabs .= '<button class="smc-tab" type="button" data-cat="' . $k . '" aria-pressed="' . ($k === 'all' ? 'true' : 'false') . '">' . $e($label) . '</button>';
}

$section = "<!-- ===== Slash Mark Digital Ecosystem: Composer Suite (32 tools) ===== -->\n" . $css . '
<section class="smc" id="composer-suite" aria-labelledby="smc-title">
  <div class="smc-hero">
    <span class="smc-eyebrow">Digital Ecosystem</span>
    <h2 id="smc-title"><span>Composer Suite</span></h2>
    <p>PHP Package Intelligence Suite. Explore, analyze, and manage Composer packages with our specialized toolset.</p>
    <div class="smc-install"><code>composer global require slashmark/composer-suite</code><button class="smc-copy" type="button">Copy</button></div>
    <div class="smc-pills"><span class="smc-pill">32 tools · one command</span><span class="smc-pill">Live Packagist data</span><span class="smc-pill">FriendsOfPHP + GitHub advisories</span><span class="smc-pill">JSON output &amp; CI exit codes</span><span class="smc-pill">PHP 8.1+ · zero dependencies</span></div>
  </div>
  <div class="smc-tabs" role="group" aria-label="Filter tools">' . $tabs . '</div>
  <div class="smc-grid">
' . $cards . '  </div>
  <p class="smc-foot">Run <code>sm-composer &lt;tool&gt; --help</code> for every option · <a href="https://packagist.org/packages/slashmark/composer-suite" target="_blank" rel="noopener">View on Packagist →</a></p>
  <div class="smc-cta">
    <div>
      <h3>Enterprise PHP Solutions</h3>
      <p>Need specialized PHP development or Composer package management solutions? Our team of PHP experts is here to help.</p>
    </div>
    <a class="smc-btn" href="mailto:mukesh@slashmark.in?subject=Enterprise%20PHP%20Solutions">Talk to our PHP experts</a>
  </div>
</section>' . "\n" . $js . "\n<!-- ===== end Composer Suite ===== -->\n";

$dir = __DIR__;
file_put_contents("$dir/composer-suite.html", $section);
file_put_contents("$dir/preview.html", '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Composer Suite — Slash Mark</title><style>body{margin:0;background:#020617}main{max-width:1180px;margin:0 auto;padding:40px 16px}</style></head><body><main>' . "\n" . $section . '</main></body></html>' . "\n");
echo 'website section written: ' . count($tools) . " tools\n";
