<?php

namespace Ux1\UxStudio\Modules\PopupManager;

use Joomla\CMS\Language\Text;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/** Route handlers of the popup-manager module (administration + the public tracking endpoint). */
final class Api
{
    public function __construct(private ModuleContext $ctx)
    {
    }

    private function store(): Store
    {
        return new Store($this->ctx->db());
    }

    private function popupOrFail(int $id): array
    {
        $popup = $id > 0 ? $this->store()->get($id) : null;
        if (!$popup) {
            throw new ApiException(Text::_('UXS_POPUP_MANAGER_ERR_NOT_FOUND'), 404, 'not_found');
        }

        return $popup;
    }

    /** GET popups */
    public function list(ApiRequest $r): array
    {
        return ['popups' => $this->store()->all()];
    }

    /** GET popups/{id} */
    public function get(ApiRequest $r): array
    {
        return $this->popupOrFail((int) $r->param('id'));
    }

    /** POST popups (create) */
    public function create(ApiRequest $r): array
    {
        return $this->store()->save(0, $r->body());
    }

    /** POST popups/{id} (update; keys that are not sent keep their value) */
    public function update(ApiRequest $r): array
    {
        $id = (int) $r->param('id');
        $this->popupOrFail($id);

        return $this->store()->save($id, $r->body());
    }

    /** POST popups/{id}/status {status: publish|draft} */
    public function status(ApiRequest $r): array
    {
        $id = (int) $r->param('id');
        $this->popupOrFail($id);
        $status = $r->string('status');
        if (!\in_array($status, ['publish', 'draft'], true)) {
            throw new ApiException('status: invalid option', 422, 'invalid_value');
        }
        $this->store()->setStatus($id, $status);

        return $this->popupOrFail($id);
    }

    /** POST popups/{id}/duplicate - the copy is a draft with a new title. */
    public function duplicate(ApiRequest $r): array
    {
        $popup = $this->popupOrFail((int) $r->param('id'));
        unset($popup['id'], $popup['stats']);
        $popup['title']  = mb_substr($popup['title'], 0, 170) . ' ' . Text::_('UXS_POPUP_MANAGER_COPY_SUFFIX');
        $popup['status'] = 'draft';

        return $this->store()->save(0, $popup);
    }

    /** POST popups/{id}/delete */
    public function delete(ApiRequest $r): array
    {
        $id = (int) $r->param('id');
        $this->popupOrFail($id);
        $this->store()->delete($id);

        return ['deleted' => true];
    }

    /** POST popups/{id}/reset-stats */
    public function resetStats(ApiRequest $r): array
    {
        $id = (int) $r->param('id');
        $this->popupOrFail($id);
        $this->store()->resetStats($id);

        return ['reset' => true];
    }

    /** GET popups/{id}/preview-url - signed front-end link that shows the popup at once (1 hour). */
    public function previewUrl(ApiRequest $r): array
    {
        $id = (int) $r->param('id');
        $this->popupOrFail($id);

        return ['url' => Support::previewUrl('popup', $id)];
    }

    /** GET menu-items - published site menu items for the targeting picker. */
    public function menuItems(ApiRequest $r): array
    {
        $out = [];
        foreach (Support::menuOptions($this->ctx->db()) as $id => $label) {
            $out[] = ['id' => (int) $id, 'label' => $label];
        }

        return ['items' => $out];
    }

    /** PUBLIC POST track {popup_id, event} - never reveals whether the popup exists. */
    public function track(ApiRequest $r): array
    {
        if (!$this->ctx->setting('tracking', true)) {
            return ['ok' => true];
        }
        $id    = $r->int('popup_id');
        $event = $r->string('event');
        if ($id > 0 && \in_array($event, Store::EVENTS, true)) {
            $store = $this->store();
            if ($store->isPublished($id)) {
                $store->track($id, $event);
            }
        }

        return ['ok' => true];
    }
}
