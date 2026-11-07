<?php

namespace Ux1\UxStudio\Modules\Redirects;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;

\defined('_JEXEC') or die;

/**
 * Administrator API of the Redirects module (called by the SPA through the UX Studio JSON endpoint).
 */
final class Api
{
    private const CONFLICTS = ['update', 'skip', 'error'];

    /** GET redirects/status - things the screen must warn about. */
    public function status(ApiRequest $r): array
    {
        $db    = Repository::db();
        $count = static fn (string $table): int => (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName($table)))->loadResult();

        return [
            // Joomla's own redirect plugin handles 404s too (and logs them into com_redirect). Our rules run first.
            'joomla_redirect_plugin' => PluginHelper::isEnabled('system', 'redirect'),
            'joomla_redirect_component' => ComponentHelper::isEnabled('com_redirect'),
            'joomla_links'           => Importer::joomlaAvailable(),
            'rules'                  => $count(Repository::TABLE),
            'log_404'                => $count(NotFoundLog::TABLE),
            'sef'                    => (bool) $this->app()->get('sef'),
            'rewrite'                => (bool) $this->app()->get('sef_rewrite'),
        ];
    }

    /** GET redirects/rules */
    public function listRules(ApiRequest $r): array
    {
        return Repository::list([
            'search'     => $r->string('search'),
            'code'       => $r->int('code'),
            'origin'     => $r->string('origin'),
            'match_type' => $r->string('match_type'),
            'enabled'    => $r->query('enabled'),
            'orderby'    => $r->string('orderby'),
            'order'      => $r->string('order'),
            'limit'      => $r->int('limit', 50),
            'offset'     => $r->int('offset'),
        ]);
    }

    /** POST redirects/rules - bulk upsert {items, dry_run, on_conflict} */
    public function upsert(ApiRequest $r): array
    {
        $items = $r->body()['items'] ?? null;
        if (!\is_array($items) || !$items) {
            throw new ApiException(Text::_('UXS_REDIRECTS_E_ITEMS'), 422, 'invalid_items');
        }

        return Repository::bulk($items, $this->conflict($r), $r->bool('dry_run'), 'manual');
    }

    /** POST redirects/rules/{id} - partial update of one rule */
    public function updateOne(ApiRequest $r): array
    {
        $input       = $r->body();
        $input['id'] = (int) $r->param('id');
        $result      = Repository::save($input, 'error');
        if ($result['status'] === 'error') {
            throw new ApiException((string) $result['errors'][0]['message'], 422, 'invalid_rule');
        }

        return $result;
    }

    /** POST redirects/rules/delete {ids} */
    public function deleteRules(ApiRequest $r): array
    {
        return ['deleted' => Repository::delete((array) ($r->body()['ids'] ?? []))];
    }

    /** POST redirects/rules/reset-hits {ids} or {all:true} */
    public function resetHits(ApiRequest $r): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($r->body()['ids'] ?? []))));
        if (!$r->bool('all') && !$ids) {
            throw new ApiException(Text::_('UXS_REDIRECTS_E_IDS'), 422, 'invalid_ids');
        }

        return ['reset' => Repository::resetHits($r->bool('all') ? null : $ids)];
    }

    /** POST redirects/test {urls, live} */
    public function test(ApiRequest $r): array
    {
        $live = $r->bool('live');
        $urls = \array_slice(array_values(array_filter(array_map('strval', array_filter((array) ($r->body()['urls'] ?? []), 'is_scalar')))), 0, 50);

        return array_map(static fn (string $url): array => Tester::test($url, $live), $urls);
    }

    /** POST redirects/audit {live, live_limit, fix_chains} */
    public function audit(ApiRequest $r): array
    {
        return Tester::audit($r->bool('live'), max(1, min(500, $r->int('live_limit', 50))), $r->bool('fix_chains'));
    }

    /** GET redirects/404 */
    public function list404(ApiRequest $r): array
    {
        return NotFoundLog::list([
            'search'          => $r->string('search'),
            'min_hits'        => $r->int('min_hits', 1),
            'unresolved_only' => $r->bool('unresolved_only'),
            'orderby'         => $r->string('orderby'),
            'limit'           => $r->int('limit', 50),
            'offset'          => $r->int('offset'),
            'suggest'         => $r->bool('suggest'),
        ]);
    }

    /** POST redirects/404/delete {ids} or {all:true} */
    public function delete404(ApiRequest $r): array
    {
        return ['deleted' => NotFoundLog::delete((array) ($r->body()['ids'] ?? []), $r->bool('all'))];
    }

    /** GET redirects/suggest?url=&title= */
    public function suggest(ApiRequest $r): array
    {
        return ['items' => (new Suggester())->candidates($r->string('url'), $r->string('title'), $r->int('limit', 5))];
    }

    /** POST redirects/import/csv {csv, dry_run, on_conflict} */
    public function importCsv(ApiRequest $r): array
    {
        $csv = $r->string('csv');
        if (trim($csv) === '') {
            throw new ApiException(Text::_('UXS_REDIRECTS_E_CSV_EMPTY'), 422, 'csv_empty');
        }
        if (\strlen($csv) > Importer::MAX_CSV) {
            throw new ApiException(Text::_('UXS_REDIRECTS_E_CSV_BIG'), 413, 'csv_too_big');
        }

        return Importer::csv($csv, $this->conflict($r), $r->bool('dry_run'));
    }

    /** POST redirects/import/joomla {dry_run, on_conflict} */
    public function importJoomla(ApiRequest $r): array
    {
        return Importer::joomla($this->conflict($r), $r->bool('dry_run'));
    }

    /** GET redirects/export - CSV text inside the JSON envelope (the SPA saves it as a file) */
    public function export(ApiRequest $r): array
    {
        return ['csv' => Importer::exportCsv()];
    }

    private function conflict(ApiRequest $r): string
    {
        $value = $r->string('on_conflict');

        return \in_array($value, self::CONFLICTS, true) ? $value : 'update';
    }

    private function app(): \Joomla\CMS\Application\CMSApplicationInterface
    {
        return \Joomla\CMS\Factory::getApplication();
    }
}
