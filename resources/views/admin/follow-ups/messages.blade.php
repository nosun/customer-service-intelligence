@if(session('follow_up_success'))<div class="alert alert-success">{{ session('follow_up_success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul style="margin-bottom:0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
