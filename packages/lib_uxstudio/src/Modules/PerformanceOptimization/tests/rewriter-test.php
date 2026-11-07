<?php
// CLI regression test: php rewriter-test.php   (exit code 1 on failure)
define('_JEXEC', 1);
require __DIR__ . '/../Rewriter.php';

use Ux1\UxStudio\Modules\PerformanceOptimization\Rewriter;

$fail = 0;
$check = function (string $name, bool $ok) use (&$fail): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    $fail += $ok ? 0 : 1;
};
$opts = ['lazy_images' => true, 'lazy_iframes' => true, 'lcp_priority' => true, 'lazy_skip' => 1];

// Cassiopeia: logo (eager) first, then hero, then more images.
$cass = '<html><body><header><a><img class="logo" src="/images/logo.png" alt="x" loading="eager" width="200" height="60"></a></header>'
    . '<main><img src="/images/hero.jpg" width=1200 height=600><img src="/images/two.jpg" width="800" height="400"></main></body></html>';
foreach ([1, 0] as $skip) {
    $out = (new Rewriter(['lazy_skip' => $skip] + $opts))->process($cass);
    $check("cassiopeia skip=$skip: hero has fetchpriority", (bool) preg_match('~<img src="/images/hero.jpg"[^>]*fetchpriority="high"~', $out));
    $check("cassiopeia skip=$skip: hero not lazy", !preg_match('~<img src="/images/hero.jpg"[^>]*loading=~', $out));
    $check("cassiopeia skip=$skip: second image lazy", (bool) preg_match('~two.jpg"[^>]*loading="lazy"~', $out));
    $check("cassiopeia skip=$skip: logo untouched", str_contains($out, '<img class="logo" src="/images/logo.png" alt="x" loading="eager" width="200" height="60">'));
}
// Logo without own loading attribute is not lazy inside the window and does not use it up.
$out = (new Rewriter($opts))->process('<body><img class="logo" src="/l.png" width="100" height="40"><img src="/h.jpg" width="900" height="500"><img src="/b.jpg" width="900" height="500"></body></html>');
$check('logo without loading is not lazy', !preg_match('~l\.png"[^>]*loading~', $out));
$check('hero priority, no lazy', (bool) preg_match('~h\.jpg"[^>]*fetchpriority="high"~', $out) && !preg_match('~h\.jpg"[^>]*loading~', $out));

// Markup inside attribute values must stay byte-exact.
$attr = '<div data-template="<img src=z><iframe src=y></iframe><script>x</script><!-- c -->" title=\'<img src=q>\'><img src="/a.jpg" width="900" height="500"><img src="/b.jpg" width="900" height="500"></div></html>';
$out = (new Rewriter($opts + ['strip_comments' => true, 'minify_html' => true]))->process($attr);
$check('attribute value untouched', str_contains($out, 'data-template="<img src=z><iframe src=y></iframe><script>x</script><!-- c -->" title=\'<img src=q>\''));
$check('real second image lazy', (bool) preg_match('~b\.jpg"[^>]*loading="lazy"~', $out));

$out = (new Rewriter($opts))->process('<p><iframe src="https://e.com/v"></iframe><iframe src="/x" loading="eager"></iframe></p></html>');
$check('iframe lazy', str_contains($out, '<iframe src="https://e.com/v" loading="lazy">') && str_contains($out, 'loading="eager">'));

$out = (new Rewriter(['defer_scripts' => true, 'strip_comments' => true]))->process("<head><!-- gone --><!--[if IE]>keep<![endif]--><script src=\"/a.js\"></script><script>var a = '<!-- x -->';</script></head><body><pre>  a\n b</pre></body></html>");
$check('comment stripped, conditional kept', !str_contains($out, 'gone') && str_contains($out, '<!--[if IE]>keep<![endif]-->'));
$check('external script deferred, inline untouched', str_contains($out, '<script src="/a.js" defer></script>') && str_contains($out, "<script>var a = '<!-- x -->';</script>"));

exit($fail ? 1 : 0);
