<?php
 
function planner_preferences(array $input): array
{
    $preferences = [
        'start' => $input['start'] ?? '08:00',
        'hours' => $input['hours'] ?? 8,
        'pace' => $input['pace'] ?? 'Balanced',
        'mood' => $input['mood'] ?? 'Any',
        'companion' => $input['companion'] ?? 'Solo'
    ];
    if (!is_string($preferences['start'])
        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $preferences['start'])) {
        throw new InvalidArgumentException('Enter a valid start time.');
    }
    $allowed = [
        'pace' => ['Relaxed', 'Balanced', 'Fast'],
        'mood' => ['Any', 'Nature', 'Adventure', 'Food & Culture', 'History'],
        'companion' => ['Solo', 'Family', 'Friends']
    ];
    foreach ($allowed as $field => $choices) {
        if (!is_string($preferences[$field]) || !in_array($preferences[$field], $choices, true)) {
            throw new InvalidArgumentException('Choose a valid ' . $field . '.');
        }
    }
    $rawHours = $preferences['hours'];
    $hours = (is_string($rawHours) || is_int($rawHours) || is_float($rawHours))
        ? filter_var($rawHours, FILTER_VALIDATE_FLOAT) : false;
    if ($hours === false || !is_finite($hours) || $hours < 1 || $hours > 16) {
        throw new InvalidArgumentException('Available time must be between 1 and 16 hours.');
    }
    $preferences['hours'] = (float) $hours;
    return $preferences;
}

 
function planner_coordinates(array $point, string $latitudeKey, string $longitudeKey): array
{
    $result = [];
    foreach ([$latitudeKey => [-90, 90], $longitudeKey => [-180, 180]] as $key => $range) {
        $raw = $point[$key] ?? null;
        $value = (is_string($raw) || is_int($raw) || is_float($raw))
            ? filter_var($raw, FILTER_VALIDATE_FLOAT) : false;
        if ($value === false || !is_finite($value) || $value < $range[0] || $value > $range[1]) {
            throw new InvalidArgumentException('Check the ' . $key . ' coordinate.');
        }
        $result[] = (float) $value;
    }
    return $result;
}

function planner_settings(array $cfg): array
{
    [$homeLatitude, $homeLongitude] = planner_coordinates($cfg, 'home_lat', 'home_lng');
    $numbers = [];
    foreach (['road_factor', 'average_speed_kmh'] as $key) {
        $raw = $cfg[$key] ?? null;
        $value = (is_string($raw) || is_int($raw) || is_float($raw))
            ? filter_var($raw, FILTER_VALIDATE_FLOAT) : false;
        if ($value === false || !is_finite($value) || $value <= 0) {
            throw new InvalidArgumentException('Configure a positive ' . $key . ' value.');
        }
        $numbers[$key] = (float) $value;
    }
    return [$homeLatitude, $homeLongitude, $numbers['road_factor'], $numbers['average_speed_kmh']];
}

 
function planner_places(array $places): array
{
    $validated = [];
    $seen = [];
    foreach ($places as $place) {
        if (!is_array($place)) {
            throw new InvalidArgumentException('A selected place record is invalid.');
        }
        $rawId = $place['id'] ?? null;
        $id = (is_int($rawId) || is_string($rawId))
            ? filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('A selected place has an invalid ID.');
        }
        [$latitude, $longitude] = planner_coordinates($place, 'latitude', 'longitude');
        $rawDuration = $place['visit_minutes'] ?? null;
        $duration = (is_string($rawDuration) || is_int($rawDuration))
            ? filter_var($rawDuration, FILTER_VALIDATE_INT) : false;
        if ($duration === false || $duration < 5 || $duration > 480) {
            throw new InvalidArgumentException('Place visit duration must be 5 to 480 minutes.');
        }
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $place['id'] = $id;
        $place['latitude'] = $latitude;
        $place['longitude'] = $longitude;
        $place['visit_minutes'] = $duration;
        $validated[] = $place;
    }
    return $validated;
}

 
function planner_opening_minutes($value): ?int
{
    if (!is_string($value)
        || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::00)?$/D', $value, $parts)) {
        return null;
    }
    return (int) $parts[1] * 60 + (int) $parts[2];
}

function planner_opening_notes(array $place, int $arrival, int $departure): array
{
    $opening = planner_opening_minutes($place['open_time'] ?? null);
    $closing = planner_opening_minutes($place['close_time'] ?? null);
    if ($opening === null || $closing === null) {
        return ['Opening hours are not verified or are incomplete. Confirm before visiting.'];
    }
    if ($opening === $closing) {
        
        return ['Opening and closing times are identical. Confirm the actual visiting hours.'];
    }

    $dayStart = intdiv($arrival, 1440) * 1440;
    $opensAt = $dayStart + $opening;
    $closesAt = $dayStart + $closing;

    if ($closing < $opening) {
         
        $closesAt += 1440;
        if ($arrival % 1440 <= $closing) {
            $opensAt -= 1440;
            $closesAt -= 1440;
        }
    }

    $notes = [];
    if ($arrival < $opensAt) {
        $notes[] = 'Arrival is before opening (' . substr($place['open_time'], 0, 5) . ').';
    } elseif ($arrival >= $closesAt) {
        $notes[] = 'Arrival is at or after closing (' . substr($place['close_time'], 0, 5) . ').';
    }
    if ($arrival < $closesAt && $departure > $closesAt) {
        $notes[] = 'The visit extends beyond closing time.';
    }
    return $notes;
}

function generate_plan(array $places, array $input, array $cfg): array
{
    if (!$places) {
        throw new InvalidArgumentException('Select at least one place to create a plan.');
    }
    $preferences = planner_preferences($input);
    [$latitude, $longitude, $roadFactor, $speed] = planner_settings($cfg);
    $remaining = planner_places($places);
    $paceFactors = ['Relaxed' => 1.25, 'Balanced' => 1.0, 'Fast' => 0.75];
    [$hour, $minute] = array_map('intval', explode(':', $preferences['start']));
    $startMinutes = $hour * 60 + $minute;
    $clock = $startMinutes;
    $stops = [];
    $totalDistance = 0.0;
    $totalTravel = 0;
    $totalVisits = 0;

    while ($remaining) {
       
        $nearestIndex = 0;
        $nearestDistance = INF;
        foreach ($remaining as $index => $place) {
            $distance = haversine($latitude, $longitude, $place['latitude'], $place['longitude']);
            if ($distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearestIndex = $index;
            }
        }
        $place = $remaining[$nearestIndex];
        array_splice($remaining, $nearestIndex, 1);

        $legKm = $nearestDistance * $roadFactor;
        $travelEstimate = ceil($legKm / $speed * 60);
        if (!is_finite($legKm) || !is_finite($travelEstimate)
            || $travelEstimate >= PHP_INT_MAX - $clock - 600) {
            throw new InvalidArgumentException('Travel settings produce an invalid estimate. Check the speed and road factor.');
        }
        $travelMinutes = (int) $travelEstimate;
        $visitMinutes = (int) ceil($place['visit_minutes'] * $paceFactors[$preferences['pace']]);
        $arrival = $clock + $travelMinutes;
        $departure = $arrival + $visitMinutes;
        $notes = planner_opening_notes($place, $arrival, $departure);
        if (empty($place['verified'])) {
            $notes[] = 'Location and visitor details need local verification.';
        }

        $stops[] = [
            'place' => $place,
            'arrival' => $arrival,
            'end' => $departure,
            'visit_minutes' => $visitMinutes,
            'travel_minutes' => $travelMinutes,
            'leg_km' => round($legKm, 2),
            'warnings' => $notes
        ];
        $totalDistance += $legKm;
        $totalTravel += $travelMinutes;
        $totalVisits += $visitMinutes;
        $clock = $departure;
        $latitude = $place['latitude'];
        $longitude = $place['longitude'];
    }

    $totalMinutes = $clock - $startMinutes;
    $warnings = [];
    $budgetMinutes = $preferences['hours'] * 60;
    if ($totalMinutes > $budgetMinutes) {
        $extraMinutes = (int) ceil($totalMinutes - $budgetMinutes);
        $warnings[] = 'This plan exceeds your available time by ' . $extraMinutes
            . ' minutes. Remove a stop or choose a faster pace.';
    }
    if ($clock >= 1440) {
        $warnings[] = 'This schedule continues into the next day. Adjust it for a one-day visit.';
    }

   
    return [
        'stops' => $stops,
        'start' => $preferences['start'],
        'pace' => $preferences['pace'],
        'hours' => $preferences['hours'],
        'mood' => $preferences['mood'],
        'companion' => $preferences['companion'],
        'total_km' => round($totalDistance, 2),
        'travel_minutes' => $totalTravel,
        'visit_minutes' => $totalVisits,
        'total_minutes' => $totalMinutes,
        'finish' => $clock,
        'warnings' => $warnings
    ];
}

function recommend_places(array $places, array $input, array $cfg): array
{
    $preferences = planner_preferences($input);
    [$homeLatitude, $homeLongitude] = planner_settings($cfg);
    $matching = [];
   
    foreach (planner_places($places) as $place) {
        $tags = is_string($place['mood_tags'] ?? null)
            ? array_map('trim', explode(',', $place['mood_tags'])) : [];
        $matchesMood = $preferences['mood'] === 'Any'
            || in_array($preferences['mood'], $tags, true);
        $matchesGroup = $preferences['companion'] !== 'Family'
            || in_array($place['kid_friendly'] ?? 0, [1, '1', true], true);
        if ($matchesMood && $matchesGroup) {
             
            $place['distance_km'] = haversine(
                $homeLatitude, $homeLongitude, $place['latitude'], $place['longitude']
            );
            $matching[] = $place;
        }
    }
    usort($matching, fn($first, $second) => $first['distance_km'] <=> $second['distance_km']);

    $chosen = [];
    $budgetMinutes = $preferences['hours'] * 60;
    foreach ($matching as $place) {
        $candidate = generate_plan([...$chosen, $place], $preferences, $cfg);
        
        if ($candidate['total_minutes'] <= $budgetMinutes && $candidate['finish'] < 1440) {
            $chosen[] = $place;
        }
    }
    return $chosen;
}
