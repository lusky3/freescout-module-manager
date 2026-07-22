@if ($generalErrorKeys->isNotEmpty())
    <div class="alert alert-danger alert-dismissible">
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
    <div class="alert alert-success alert-dismissible">
        <button type="button" class="close" data-dismiss="alert" aria-label="{{ __('Close') }}"><span aria-hidden="true">&times;</span></button>
        {{ session('success') }}
    </div>
@endif
@if (session('warning'))
    <div class="alert alert-warning alert-dismissible">
        <button type="button" class="close" data-dismiss="alert" aria-label="{{ __('Close') }}"><span aria-hidden="true">&times;</span></button>
        {{ session('warning') }}
        <a href="{{ url('/modules/list') }}" class="btn btn-xs btn-default" style="margin-left: 10px;">
            {{ __('Enable manually') }}
        </a>
    </div>
@endif

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
                                <form method="post" action="{{ route('modulemanager_install_repo', $repo->id) }}" style="display:inline">
                                    {{ csrf_field() }}
                                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Install') }}</button>
                                </form>
                                <form method="post" action="{{ route('modulemanager_remove_repo', $repo->id) }}" style="display:inline; margin-left: 10px;">
                                    {{ csrf_field() }}
                                    {{ method_field('DELETE') }}
                                    <button type="submit" class="btn btn-sm btn-default" data-confirm-inline="{{ __('Click again to confirm') }}">{{ __('Remove') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-muted">{{ __('No saved repositories yet.') }}</td>
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
                <form method="post" action="{{ route('modulemanager_add_repo') }}">
                    {{ csrf_field() }}
                    <div class="row">
                        @php
                            $repoFormFields = [
                                ['name' => 'owner', 'label' => __('GitHub owner'), 'placeholder' => __('e.g. octocat'), 'colClass' => 'col-md-3', 'default' => ''],
                                ['name' => 'repo', 'label' => __('Repository name'), 'placeholder' => __('e.g. AiAssistant'), 'colClass' => 'col-md-3', 'default' => ''],
                                ['name' => 'ref', 'label' => __('Branch or tag'), 'placeholder' => null, 'colClass' => 'col-md-2', 'default' => 'main'],
                                ['name' => 'label', 'label' => __('Display name'), 'placeholder' => __('e.g. AI Assistant'), 'colClass' => 'col-md-3', 'default' => ''],
                            ];
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
            // Second click while armed: let it proceed to submit.
            return;
        }

        event.preventDefault();

        var confirmMessage = target.getAttribute('data-confirm-inline');
        target.setAttribute('data-original-text', target.getAttribute('data-original-text') || target.textContent);
        target.setAttribute('data-confirm-armed', '1');
        target.classList.add('btn-danger');
        target.textContent = confirmMessage;

        setTimeout(function () {
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
})();
</script>
