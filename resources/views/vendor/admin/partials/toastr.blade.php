@if(Session::has('toastr'))
    @php
        $toastr = Session::pull('toastr');
        $value = static function (string $key, mixed $default = []) use ($toastr): mixed {
            return $toastr instanceof \Illuminate\Support\MessageBag
                ? $toastr->get($key)
                : \Illuminate\Support\Arr::get($toastr, $key, $default);
        };
        $type = \Illuminate\Support\Arr::get($value('type'), 0, 'success');
        $message = \Illuminate\Support\Arr::get($value('message'), 0, '');
        $options = \Illuminate\Support\Arr::get($value('options'), 0, []);
    @endphp
    <script>
        $(function () {
            toastr[@json($type)](@json($message), null, @json($options));
        });
    </script>
@endif
