<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Requests;

use Hypervel\Foundation\Http\FormRequest;
use Ipsocode\Acl\Http\Rules\WorkspaceParent;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Workspaces\Reach;

class WorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var null|string $current */
        $current = $this->route('aclWorkspace');

        return [
            'name' => ['required', 'string'],
            'kind' => ['sometimes', 'nullable', 'string'],
            'meta' => ['sometimes', 'nullable', 'array'],
            Workspaces::parentColumn() => [...($current === null ? [] : ['sometimes']), new WorkspaceParent(app(Reach::class), $current)],
        ];
    }
}
