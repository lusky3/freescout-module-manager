@if ($generalErrorKeys->isNotEmpty())
    <div class="alert alert-danger alert-dismissible" role="alert">
        <button type="button" class="close" data-dismiss="alert" aria-label="{{ __('Close') }}"><span aria-hidden="true">&times;</span></button>
        <ul class="mb-0">
            @foreach ($generalErrorKeys as $errorKey)
                @foreach ($errors->get($errorKey) as $errorMessage)
                    <li>{{ $errorMessage }}</li>
                @endforeach
            @endforeach
        </ul>
    </div>
@endif

@if (session('success'))
    <div class="alert alert-success alert-dismissible" role="alert" aria-live="polite">
        <button type="button" class="close" data-dismiss="alert" aria-label="{{ __('Close') }}"><span aria-hidden="true">&times;</span></button>
        {{ session('success') }}
    </div>
@endif
@if (session('warning'))
    <div class="alert alert-warning alert-dismissible" role="alert">
        <button type="button" class="close" data-dismiss="alert" aria-label="{{ __('Close') }}"><span aria-hidden="true">&times;</span></button>
        {{ session('warning') }}
        <a href="{{ url('/modules/list') }}" class="btn btn-xs btn-default" style="margin-left: 10px;">
            {{ __('Enable manually') }}
        </a>
    </div>
@endif

<div class="panel panel-default">
    <div class="panel-heading">{{ __('Module Catalog') }}</div>
    <div class="panel-body">
        <div class="alert alert-warning" role="alert">
            {{ __("These modules are written and maintained by other people, not this project. Being listed here means an automated review found nothing obviously malicious — not that the module is safe, well-maintained, or fit for your use case. Read the repo yourself before installing. You're choosing to run someone else's code inside your FreeScout instance; that's on you, not us.") }}
        </div>

        @if (count($catalog) > 0)
            <input type="text" id="catalog-filter" class="form-control" style="margin-bottom: 15px;" placeholder="{{ __('Filter by name or description...') }}">
        @endif

        <div id="catalog-list">
            @forelse ($catalog as $item)
                @php
                    // Plain destructuring alias, not presentation logic (see
                    // Task 5 brief). Written as a block-style directive
                    // rather than the inline single-line shorthand: this
                    // Blade version's raw-PHP-block preprocessor pairs the
                    // first opener it finds with the next closer anywhere
                    // later in the whole template via a regex (it doesn't
                    // know about per-block scoping) -- an unmatched inline
                    // opener here would have been paired with the closer of
                    // the unrelated raw-PHP block further down in the
                    // "Install a Module" panel, swallowing everything in
                    // between as unparsed raw PHP.
                    $entry = $item['entry'];
                @endphp
                <div class="panel panel-default catalog-entry" data-catalog-search="{{ strtolower($entry->name . ' ' . $entry->description) }}">
                    <div class="panel-body">
                        <div class="row">
                            @if ($entry->screenshotUrl)
                                <div class="col-sm-3">
                                    <img src="{{ $entry->screenshotUrl }}" loading="lazy" class="img-thumbnail" style="max-width: 100%;" alt="{{ __(':name screenshot', ['name' => $entry->name]) }}">
                                </div>
                            @endif
                            <div class="{{ $entry->screenshotUrl ? 'col-sm-9' : 'col-sm-12' }}">
                                <h4 style="margin-top: 0;">
                                    <a href="{{ $entry->url() }}" target="_blank" rel="noopener noreferrer">{{ $entry->name }}</a>
                                </h4>
                                <p>{{ $entry->description }}</p>
                                <p class="text-muted">
                                    {{ __('By :author', ['author' => $entry->authorName ?: $entry->owner]) }}
                                    &middot; <span class="glyphicon glyphicon-star" aria-hidden="true"></span> {{ $entry->stars }}
                                    @if ($entry->lastPushedAt)
                                        &middot; {{ __('Updated :date', ['date' => date('M j, Y', strtotime($entry->lastPushedAt))]) }}
                                    @endif
                                    @if ($entry->license)
                                        &middot; {{ $entry->license }}
                                    @endif
                                </p>
                                @if ($entry->reviewNotes)
                                    <p class="text-muted"><small>{{ __('Review notes: :notes', ['notes' => $entry->reviewNotes]) }}</small></p>
                                @endif

                                @if ($item['already_saved'])
                                    <span class="label label-default">{{ __('Already in your list') }}</span>
                                @else
                                    <form method="post" action="{{ route('modulemanager_add_repo') }}" style="display:inline">
                                        {{ csrf_field() }}
                                        <input type="hidden" name="owner" value="{{ $entry->owner }}">
                                        <input type="hidden" name="repo" value="{{ $entry->repo }}">
                                        <input type="hidden" name="ref" value="{{ $entry->ref }}">
                                        <input type="hidden" name="label" value="{{ $entry->name }}">
                                        <button type="submit" class="btn btn-sm btn-primary">{{ __('Add to my list') }}</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-muted">{{ __('No catalog entries yet.') }}</p>
            @endforelse
        </div>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">{{ __('Saved GitHub Repositories') }}</div>
    <div class="panel-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>{{ __('Label') }}</th>
                        <th>{{ __('Repository') }}</th>
                        <th>{{ __('Branch/Tag') }}</th>
                        <th>{{ __('Updates') }}</th>
                        <th>{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($repos as $repo)
                        <tr>
                            <td>
                                {{ $repo->label }}
                                @if ($repo->installedFolder && is_dir(base_path('Modules/'.$repo->installedFolder)))
                                    <span class="label label-success">
                                        <span class="glyphicon glyphicon-ok" aria-hidden="true"></span>
                                        @if ($repo->installedAlias)
                                            {{ __('Installed as :alias', ['alias' => $repo->installedAlias]) }}
                                        @else
                                            {{ __('Installed') }}
                                        @endif
                                    </span>
                                @endif
                            </td>
                            <td>{{ $repo->owner }}/{{ $repo->repo }}</td>
                            <td>{{ $repo->ref }}</td>
                            <td>
                                @php
                                    $updateAvailable = $repo->isUpdateAvailable();
                                @endphp
                                @if ($updateAvailable === true)
                                    <span class="label label-warning">{{ __('Update available: :label', ['label' => $repo->latestKnownLabel]) }}</span>
                                    @if ($repo->latestKnownUrl)
                                        <a href="{{ $repo->latestKnownUrl }}" target="_blank" rel="noopener noreferrer"><small>{{ __('View') }}</small></a>
                                    @endif
                                @elseif ($updateAvailable === false)
                                    <span class="text-muted"><span class="glyphicon glyphicon-ok" aria-hidden="true"></span> {{ __('Up to date') }}</span>
                                @else
                                    <span class="text-muted">{{ __('Not checked yet') }}</span>
                                @endif
                                @if ($repo->latestCheckedAt)
                                    <br><small class="text-muted">{{ __('Checked :date', ['date' => date('M j, Y g:ia', strtotime($repo->latestCheckedAt))]) }}</small>
                                @endif
                            </td>
                            <td>
                                <form method="post" action="{{ route('modulemanager_install_repo', $repo->id) }}" style="display:inline">
                                    {{ csrf_field() }}
                                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Install') }}</button>
                                </form>
                                <form method="post" action="{{ route('modulemanager_check_update', $repo->id) }}" style="display:inline; margin-left: 10px;">
                                    {{ csrf_field() }}
                                    <button type="submit" class="btn btn-sm btn-default">{{ __('Check for Updates') }}</button>
                                </form>
                                @if ($repo->installedFolder && is_dir(base_path('Modules/'.$repo->installedFolder)) && $updateAvailable === true)
                                    <form method="post" action="{{ route('modulemanager_update_repo', $repo->id) }}" style="display:inline; margin-left: 10px;">
                                        {{ csrf_field() }}
                                        <button type="submit" class="btn btn-sm btn-success">{{ __('Update') }}</button>
                                    </form>
                                @endif
                                <form method="post" action="{{ route('modulemanager_remove_repo', $repo->id) }}" style="display:inline; margin-left: 10px;">
                                    {{ csrf_field() }}
                                    {{ method_field('DELETE') }}
                                    <button type="submit" class="btn btn-sm btn-default" data-confirm-inline="{{ __('Click again to confirm') }}">{{ __('Remove') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-muted">{{ __('No saved repositories yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">{{ __('Install a Module') }}</div>
    <div class="panel-body">
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation" class="{{ $activeInstallTab === 'github' ? 'active' : '' }}">
                <a id="install-tab-github-tab" data-toggle="tab" href="#install-tab-github" role="tab" aria-controls="install-tab-github" aria-selected="{{ $activeInstallTab === 'github' ? 'true' : 'false' }}">{{ __('Add a Repository') }}</a>
            </li>
            <li role="presentation" class="{{ $activeInstallTab === 'upload' ? 'active' : '' }}">
                <a id="install-tab-upload-tab" data-toggle="tab" href="#install-tab-upload" role="tab" aria-controls="install-tab-upload" aria-selected="{{ $activeInstallTab === 'upload' ? 'true' : 'false' }}">{{ __('Upload a ZIP') }}</a>
            </li>
        </ul>

        <div class="tab-content">
            <div id="install-tab-github" class="tab-pane fade {{ $activeInstallTab === 'github' ? 'in active' : '' }}" role="tabpanel" aria-labelledby="install-tab-github-tab" style="padding-top: 15px;">
                <form method="post" action="{{ route('modulemanager_add_repo_from_url') }}">
                    {{ csrf_field() }}
                    <div class="row">
                        <div class="col-xs-12 col-sm-8 col-md-9">
                            <div class="form-group {{ $errors->has('github_url') ? 'has-error' : '' }}">
                                <label for="add_repo_github_url">{{ __('GitHub URL') }}</label>
                                <input
                                    type="text"
                                    id="add_repo_github_url"
                                    name="github_url"
                                    class="form-control"
                                    placeholder="{{ __('e.g. https://github.com/octocat/Hello-World') }}"
                                    value="{{ old('github_url') }}"
                                    @if ($githubUrlFieldHasError) autofocus @endif
                                    @if ($errors->has('github_url')) aria-invalid="true" aria-describedby="github_url-error" @endif
                                    required
                                >
                                @if ($errors->has('github_url'))
                                    <span class="help-block" id="github_url-error">{{ $errors->first('github_url') }}</span>
                                @else
                                    <span class="help-block">{{ __('Paste a repo link and the owner, repo, branch, and name will be filled in automatically.') }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="col-xs-12 col-sm-4 col-md-3">
                            <div class="form-group">
                                <label class="hidden-xs">&nbsp;</label>
                                <button type="submit" class="btn btn-primary btn-block">{{ __('Add from URL') }}</button>
                            </div>
                        </div>
                    </div>
                </form>

                <div style="margin: 20px 0; border-top: 1px solid #e5e5e5; padding-top: 15px;">
                    <p class="text-muted">{{ __('Or add manually') }} <small>({{ __('for private repos, unusual setups, or to override what auto-detection would pick') }})</small></p>
                </div>

                <form method="post" action="{{ route('modulemanager_add_repo') }}">
                    {{ csrf_field() }}
                    <div class="row">
                        @php
                            // $addRepoFields (from ModuleManagerServiceProvider::registerViewComposer(),
                            // backed by SettingsErrorPresenter::REPO_FIELDS) is the single
                            // source of truth for which add-repo fields exist and in what
                            // order -- this map only supplies each one's *display* metadata,
                            // which necessarily has to be spelled out somewhere regardless of
                            // where the field list itself lives.
                            $repoFieldMeta = [
                                'owner' => ['label' => __('GitHub owner'), 'placeholder' => __('e.g. octocat'), 'colClass' => 'col-md-3', 'default' => ''],
                                'repo' => ['label' => __('Repository name'), 'placeholder' => __('e.g. AiAssistant'), 'colClass' => 'col-md-3', 'default' => ''],
                                'ref' => ['label' => __('Branch or tag'), 'placeholder' => null, 'colClass' => 'col-md-2', 'default' => 'main'],
                                'label' => ['label' => __('Display name'), 'placeholder' => __('e.g. AI Assistant'), 'colClass' => 'col-md-3', 'default' => ''],
                            ];
                            $repoFormFields = array_map(function ($fieldName) use ($repoFieldMeta) {
                                return array_merge(['name' => $fieldName], $repoFieldMeta[$fieldName]);
                            }, $addRepoFields);
                        @endphp
                        @foreach ($repoFormFields as $field)
                            <div class="col-xs-12 col-sm-6 {{ $field['colClass'] }}">
                                <div class="form-group {{ $errors->has($field['name']) ? 'has-error' : '' }}">
                                    <label for="add_repo_{{ $field['name'] }}">{{ $field['label'] }}</label>
                                    <input
                                        type="text"
                                        id="add_repo_{{ $field['name'] }}"
                                        name="{{ $field['name'] }}"
                                        class="form-control"
                                        @if ($field['placeholder']) placeholder="{{ $field['placeholder'] }}" @endif
                                        value="{{ old($field['name'], $field['default']) }}"
                                        @if ($firstInvalidRepoField === $field['name']) autofocus @endif
                                        @if ($errors->has($field['name'])) aria-invalid="true" aria-describedby="{{ $field['name'] }}-error" @endif
                                        required
                                    >
                                    @if ($errors->has($field['name']))
                                        <span class="help-block" id="{{ $field['name'] }}-error">{{ $errors->first($field['name']) }}</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                        <div class="col-xs-12 col-sm-6 col-md-1">
                            <div class="form-group">
                                <label class="hidden-xs">&nbsp;</label>
                                <button type="submit" class="btn btn-default btn-block">{{ __('Add') }}</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div id="install-tab-upload" class="tab-pane fade {{ $activeInstallTab === 'upload' ? 'in active' : '' }}" role="tabpanel" aria-labelledby="install-tab-upload-tab" style="padding-top: 15px;">
                <form method="post" action="{{ route('modulemanager_install_upload') }}" enctype="multipart/form-data">
                    {{ csrf_field() }}
                    <div class="form-group {{ $errors->has('module_zip') ? 'has-error' : '' }}">
                        <label for="module_zip">{{ __('Module ZIP file') }}</label>
                        <input type="file" id="module_zip" name="module_zip" accept=".zip" @if ($errors->has('module_zip')) autofocus aria-invalid="true" aria-describedby="module_zip-error" @endif required>
                        @if ($errors->has('module_zip'))
                            <span class="help-block" id="module_zip-error">{{ $errors->first('module_zip') }}</span>
                        @endif
                    </div>
                    <button type="submit" class="btn btn-primary">{{ __('Upload &amp; Install') }}</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript" {!! \Helper::cspNonceAttr() !!}>
(function () {
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') {
            return;
        }

        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.setAttribute('data-original-text', submitBtn.textContent);
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-busy', 'true');
            submitBtn.textContent = '{{ __('Working...') }}';
        }
    }, true);

    // Inline two-step "click again to confirm" pattern for destructive
    // actions, replacing window.confirm(). First click on a
    // [data-confirm-inline] element arms it (relabels + adds a danger cue)
    // and prevents the default submit; a second click within the timeout
    // window is allowed to fall through so the submit listener above can
    // take over and disable+relabel the button to "Working...".
    document.addEventListener('click', function (event) {
        var target = event.target;
        if (target && target.closest) {
            target = target.closest('[data-confirm-inline]');
        } else {
            target = null;
        }

        if (!target) {
            return;
        }

        if (target.getAttribute('data-confirm-armed') === '1') {
            // Second (confirming) click while armed: cancel the pending
            // auto-revert timeout before letting this proceed to submit.
            // Without this, a confirmed submit that takes >=3s to respond
            // would have the timeout fire mid-flight and visually revert
            // the button's text/class back to "Remove" while the button is
            // actually still disabled and the request is still in flight --
            // the label would contradict the real state.
            if (target._confirmRevertTimeoutId) {
                clearTimeout(target._confirmRevertTimeoutId);
                target._confirmRevertTimeoutId = null;
            }
            return;
        }

        event.preventDefault();

        var confirmMessage = target.getAttribute('data-confirm-inline');
        target.setAttribute('data-original-text', target.getAttribute('data-original-text') || target.textContent);
        target.setAttribute('data-confirm-armed', '1');
        target.classList.add('btn-danger');
        target.textContent = confirmMessage;

        target._confirmRevertTimeoutId = setTimeout(function () {
            target._confirmRevertTimeoutId = null;
            target.removeAttribute('data-confirm-armed');
            target.classList.remove('btn-danger');
            target.textContent = target.getAttribute('data-original-text');
        }, 3000);
    }, true);

    // Note: jQuery/Bootstrap's bundle loads later in the page (near the end of
    // <body>), so this check is deferred to fire time (inside setTimeout) rather
    // than evaluated now, otherwise it would always see jQuery as unavailable.
    var successAlerts = document.querySelectorAll('.alert-success');
    for (var i = 0; i < successAlerts.length; i++) {
        (function (el) {
            setTimeout(function () {
                var hasBootstrapAlert = typeof window.jQuery === 'function' && window.jQuery.fn && typeof window.jQuery.fn.alert === 'function';
                if (hasBootstrapAlert) {
                    window.jQuery(el).alert('close');
                } else {
                    el.style.display = 'none';
                }
            }, 5000);
        })(successAlerts[i]);
    }

    // Bootstrap 3's tab plugin (jQuery-based) fires a 'shown.bs.tab' event
    // on the tab <a> that was just activated whenever the user switches
    // tabs client-side. The initial aria-selected values above are only
    // correct for the server-rendered state; this keeps them correct after
    // a client-side switch too. Deferred to the window 'load' event (rather
    // than run immediately) since jQuery/Bootstrap load later in the page,
    // same reasoning as the successAlerts block above.
    window.addEventListener('load', function () {
        var hasJQueryOn = typeof window.jQuery === 'function' && window.jQuery.fn && typeof window.jQuery.fn.on === 'function';
        if (!hasJQueryOn) {
            return;
        }

        window.jQuery('.nav-tabs[role="tablist"] a[data-toggle="tab"]').on('shown.bs.tab', function (event) {
            var $tab = window.jQuery(event.target);
            $tab.closest('.nav-tabs').find('a[data-toggle="tab"]').attr('aria-selected', 'false');
            $tab.attr('aria-selected', 'true');
        });
    });

    var catalogFilterInput = document.getElementById('catalog-filter');
    if (catalogFilterInput) {
        catalogFilterInput.addEventListener('input', function () {
            var query = catalogFilterInput.value.toLowerCase();
            var entries = document.querySelectorAll('.catalog-entry');
            for (var i = 0; i < entries.length; i++) {
                var haystack = entries[i].getAttribute('data-catalog-search') || '';
                entries[i].style.display = haystack.indexOf(query) === -1 ? 'none' : '';
            }
        });
    }
})();
</script>
