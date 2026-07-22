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
                        <td>{{ $repo['label'] }}</td>
                        <td>{{ $repo['owner'] }}/{{ $repo['repo'] }}</td>
                        <td>{{ $repo['ref'] }}</td>
                        <td>
                            <form method="post" action="{{ route('modulemanager_install_repo', $repo['id']) }}" style="display:inline">
                                {{ csrf_field() }}
                                <button type="submit" class="btn btn-sm btn-primary">{{ __('Install') }}</button>
                            </form>
                            <form method="post" action="{{ route('modulemanager_remove_repo', $repo['id']) }}" style="display:inline" data-confirm="{{ __('Remove this saved repository? This cannot be undone.') }}">
                                {{ csrf_field() }}
                                {{ method_field('DELETE') }}
                                <button type="submit" class="btn btn-sm btn-default">{{ __('Remove') }}</button>
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

        <hr>

        <h4>{{ __('Add a Repository') }}</h4>
        <form method="post" action="{{ route('modulemanager_add_repo') }}">
            {{ csrf_field() }}
            <div class="row">
                <div class="col-xs-12 col-sm-6 col-md-3">
                    <div class="form-group {{ $errors->has('owner') ? 'has-error' : '' }}">
                        <label for="add_repo_owner">{{ __('GitHub owner') }}</label>
                        <input type="text" id="add_repo_owner" name="owner" class="form-control" placeholder="{{ __('GitHub owner') }}" value="{{ old('owner') }}" @if ($firstInvalidRepoField === 'owner') autofocus @endif required>
                        @if ($errors->has('owner'))
                            <span class="help-block">{{ $errors->first('owner') }}</span>
                        @endif
                    </div>
                </div>
                <div class="col-xs-12 col-sm-6 col-md-3">
                    <div class="form-group {{ $errors->has('repo') ? 'has-error' : '' }}">
                        <label for="add_repo_repo">{{ __('Repository name') }}</label>
                        <input type="text" id="add_repo_repo" name="repo" class="form-control" placeholder="{{ __('Repository name') }}" value="{{ old('repo') }}" @if ($firstInvalidRepoField === 'repo') autofocus @endif required>
                        @if ($errors->has('repo'))
                            <span class="help-block">{{ $errors->first('repo') }}</span>
                        @endif
                    </div>
                </div>
                <div class="col-xs-12 col-sm-6 col-md-2">
                    <div class="form-group {{ $errors->has('ref') ? 'has-error' : '' }}">
                        <label for="add_repo_ref">{{ __('Branch or tag') }}</label>
                        <input type="text" id="add_repo_ref" name="ref" class="form-control" placeholder="{{ __('Branch or tag') }}" value="{{ old('ref', 'main') }}" @if ($firstInvalidRepoField === 'ref') autofocus @endif required>
                        @if ($errors->has('ref'))
                            <span class="help-block">{{ $errors->first('ref') }}</span>
                        @endif
                    </div>
                </div>
                <div class="col-xs-12 col-sm-6 col-md-3">
                    <div class="form-group {{ $errors->has('label') ? 'has-error' : '' }}">
                        <label for="add_repo_label">{{ __('Display name') }}</label>
                        <input type="text" id="add_repo_label" name="label" class="form-control" placeholder="{{ __('Display name') }}" value="{{ old('label') }}" @if ($firstInvalidRepoField === 'label') autofocus @endif required>
                        @if ($errors->has('label'))
                            <span class="help-block">{{ $errors->first('label') }}</span>
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
</div>

<div class="panel panel-default">
    <div class="panel-heading">{{ __('Install from Uploaded ZIP') }}</div>
    <div class="panel-body">
        <form method="post" action="{{ route('modulemanager_install_upload') }}" enctype="multipart/form-data">
            {{ csrf_field() }}
            <div class="form-group {{ $errors->has('module_zip') ? 'has-error' : '' }}">
                <label for="module_zip">{{ __('Module ZIP file') }}</label>
                <input type="file" id="module_zip" name="module_zip" accept=".zip" @if ($errors->has('module_zip')) autofocus @endif required>
                @if ($errors->has('module_zip'))
                    <span class="help-block">{{ $errors->first('module_zip') }}</span>
                @endif
            </div>
            <button type="submit" class="btn btn-primary">{{ __('Upload &amp; Install') }}</button>
        </form>
    </div>
</div>

<script type="text/javascript" {!! \Helper::cspNonceAttr() !!}>
(function () {
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') {
            return;
        }

        var confirmMessage = form.getAttribute('data-confirm');
        if (confirmMessage && !window.confirm(confirmMessage)) {
            event.preventDefault();
            return;
        }

        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.setAttribute('data-original-text', submitBtn.textContent);
            submitBtn.disabled = true;
            submitBtn.textContent = '{{ __('Working...') }}';
        }
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
