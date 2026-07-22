@php
    $handledErrorFields = ['owner', 'repo', 'ref', 'label', 'module_zip'];
    $generalErrorKeys = collect($errors->keys())->diff($handledErrorFields);

    $firstInvalidRepoField = null;
    foreach (['owner', 'repo', 'ref', 'label'] as $repoField) {
        if ($errors->has($repoField)) {
            $firstInvalidRepoField = $repoField;
            break;
        }
    }

    // After a redirect-with-errors, the page reloads and BS3's tab plugin has
    // no client-side memory of which tab was open. Compute the correct tab
    // server-side so an upload-specific error is never left stranded inside
    // a hidden inactive tab-pane.
    $activeInstallTab = $errors->has('module_zip') ? 'upload' : 'github';
@endphp

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
                                {{ $repo['label'] }}
                                @if (!empty($repo['installed_folder']) && is_dir(base_path('Modules/'.$repo['installed_folder'])))
                                    <span class="label label-success">{{ __('Installed') }}</span>
                                @endif
                            </td>
                            <td>{{ $repo['owner'] }}/{{ $repo['repo'] }}</td>
                            <td>{{ $repo['ref'] }}</td>
                            <td>
                                <form method="post" action="{{ route('modulemanager_install_repo', $repo['id']) }}" style="display:inline">
                                    {{ csrf_field() }}
                                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Install') }}</button>
                                </form>
                                <form method="post" action="{{ route('modulemanager_remove_repo', $repo['id']) }}" style="display:inline; margin-left: 10px;">
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
        <ul class="nav nav-tabs">
            <li role="presentation" class="{{ $activeInstallTab === 'github' ? 'active' : '' }}">
                <a data-toggle="tab" href="#install-tab-github">{{ __('Add a Repository') }}</a>
            </li>
            <li role="presentation" class="{{ $activeInstallTab === 'upload' ? 'active' : '' }}">
                <a data-toggle="tab" href="#install-tab-upload">{{ __('Upload a ZIP') }}</a>
            </li>
        </ul>

        <div class="tab-content">
            <div id="install-tab-github" class="tab-pane fade {{ $activeInstallTab === 'github' ? 'in active' : '' }}" style="padding-top: 15px;">
                <form method="post" action="{{ route('modulemanager_add_repo') }}">
                    {{ csrf_field() }}
                    <div class="row">
                        <div class="col-xs-12 col-sm-6 col-md-3">
                            <div class="form-group {{ $errors->has('owner') ? 'has-error' : '' }}">
                                <label for="add_repo_owner">{{ __('GitHub owner') }}</label>
                                <input type="text" id="add_repo_owner" name="owner" class="form-control" placeholder="{{ __('e.g. octocat') }}" value="{{ old('owner') }}" @if ($firstInvalidRepoField === 'owner') autofocus @endif @if ($errors->has('owner')) aria-invalid="true" aria-describedby="owner-error" @endif required>
                                @if ($errors->has('owner'))
                                    <span class="help-block" id="owner-error">{{ $errors->first('owner') }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="col-xs-12 col-sm-6 col-md-3">
                            <div class="form-group {{ $errors->has('repo') ? 'has-error' : '' }}">
                                <label for="add_repo_repo">{{ __('Repository name') }}</label>
                                <input type="text" id="add_repo_repo" name="repo" class="form-control" placeholder="{{ __('e.g. AiAssistant') }}" value="{{ old('repo') }}" @if ($firstInvalidRepoField === 'repo') autofocus @endif @if ($errors->has('repo')) aria-invalid="true" aria-describedby="repo-error" @endif required>
                                @if ($errors->has('repo'))
                                    <span class="help-block" id="repo-error">{{ $errors->first('repo') }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="col-xs-12 col-sm-6 col-md-2">
                            <div class="form-group {{ $errors->has('ref') ? 'has-error' : '' }}">
                                <label for="add_repo_ref">{{ __('Branch or tag') }}</label>
                                <input type="text" id="add_repo_ref" name="ref" class="form-control" value="{{ old('ref', 'main') }}" @if ($firstInvalidRepoField === 'ref') autofocus @endif @if ($errors->has('ref')) aria-invalid="true" aria-describedby="ref-error" @endif required>
                                @if ($errors->has('ref'))
                                    <span class="help-block" id="ref-error">{{ $errors->first('ref') }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="col-xs-12 col-sm-6 col-md-3">
                            <div class="form-group {{ $errors->has('label') ? 'has-error' : '' }}">
                                <label for="add_repo_label">{{ __('Display name') }}</label>
                                <input type="text" id="add_repo_label" name="label" class="form-control" placeholder="{{ __('e.g. AI Assistant') }}" value="{{ old('label') }}" @if ($firstInvalidRepoField === 'label') autofocus @endif @if ($errors->has('label')) aria-invalid="true" aria-describedby="label-error" @endif required>
                                @if ($errors->has('label'))
                                    <span class="help-block" id="label-error">{{ $errors->first('label') }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="col-xs-12 col-sm-6 col-md-1">
                            <div class="form-group">
                                <label class="hidden-xs">&nbsp;</label>
                                <button type="submit" class="btn btn-default btn-block">{{ __('Add') }}</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div id="install-tab-upload" class="tab-pane fade {{ $activeInstallTab === 'upload' ? 'in active' : '' }}" style="padding-top: 15px;">
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

        target.setAttribute('data-confirm-timeout-id', setTimeout(function () {
            target.removeAttribute('data-confirm-armed');
            target.classList.remove('btn-danger');
            target.textContent = target.getAttribute('data-original-text');
        }, 3000));
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
})();
</script>
