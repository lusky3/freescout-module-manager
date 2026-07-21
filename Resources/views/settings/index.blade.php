@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('warning'))
    <div class="alert alert-warning">
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
                            <form method="post" action="{{ route('modulemanager_remove_repo', $repo['id']) }}" style="display:inline">
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
        <form method="post" action="{{ route('modulemanager_add_repo') }}" class="form-inline">
            {{ csrf_field() }}
            <input type="text" name="owner" class="form-control" placeholder="{{ __('GitHub owner') }}" required>
            <input type="text" name="repo" class="form-control" placeholder="{{ __('Repository name') }}" required>
            <input type="text" name="ref" class="form-control" placeholder="{{ __('Branch or tag') }}" value="main" required>
            <input type="text" name="label" class="form-control" placeholder="{{ __('Display name') }}" required>
            <button type="submit" class="btn btn-default">{{ __('Add') }}</button>
        </form>
    </div>
</div>
