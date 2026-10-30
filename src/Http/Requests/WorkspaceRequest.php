<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Requests;

use Closure;
use Hypervel\Foundation\Http\FormRequest;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Workspace;
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
            Workspaces::parentColumn() => ['sometimes', 'nullable', function (string $attribute, mixed $parent, Closure $fail) use ($current): void {
                if (! Workspaces::query()->whereKey($parent)->whereKey(app(Reach::class)->beneath(Workspace::id()))->exists()) {
                    $fail('The parent workspace is not one the org role covers.');

                    return;
                }

                if ($current !== null && in_array((string) $parent, array_map('strval', [$current, ...Workspaces::descendantKeys([$current])]), true)) {
                    $fail('A workspace cannot be beneath itself or a workspace beneath it.');
                }
            }],
        ];
    }
}
