@props(['user', 'size' => 40])
@if($user->profile_photo_url)
    <img class="user-avatar" src="{{ $user->profile_photo_url }}" alt="{{ $user->name }} profile picture" width="{{ $size }}" height="{{ $size }}" style="width:{{ $size }}px;height:{{ $size }}px">
@else
    <span class="user-avatar user-avatar-initials" role="img" aria-label="{{ $user->name }}" style="width:{{ $size }}px;height:{{ $size }}px">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
@endif
