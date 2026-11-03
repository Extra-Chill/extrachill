"""Offline generation and grading regression tests; no WordPress required."""
import base64
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

HERE = Path(__file__).resolve().parent


class JourneyTests(unittest.TestCase):
    def test_generated_document(self):
        before = (HERE / 'journey.json').read_bytes()
        subprocess.run(['python3', str(HERE / 'build-journey.py')], cwd='/tmp', check=True)
        self.assertEqual(before, (HERE / 'journey.json').read_bytes())
        doc = json.loads(before)
        self.assertEqual(doc['id'], 'show-ratings-fan')
        self.assertEqual(len(doc['steps']), 3)
        for step in doc['steps']:
            self.assertIn('viewport=390x844', step['args'])
            actions = json.loads(next(a[11:] for a in step['args'] if a.startswith('steps-json=')))
            self.assertTrue(any(a['kind'] == 'screenshot' for a in actions))

    def grade(self, observations):
        data = {'fixture': {'events_blog_id': 2, 'fan_id': 711, 'other_id': 712,
                            'recent_id': 101, 'older_id': 102}, 'observations': observations}
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'data.json'
            path.write_text(json.dumps(data))
            php = '''$data=json_decode(file_get_contents($argv[1]),true);
function get_site_option($key,$default){global $data;return $key==='ec_show_ratings_observations'?$data['observations']:$data['fixture'];}
function wp_json_encode($value){return json_encode($value);}
require $argv[2];'''
            result = subprocess.run(['php', '-r', php, str(path), str(HERE / 'grade.php')], check=True, capture_output=True, text=True)
            return json.loads(base64.b64decode(result.stdout.split(':', 1)[1]))

    def observations(self):
        rows = []
        stages = [('owner-initial', 0), ('owner-picker', 0), ('owner-four', 4),
                  ('owner-three', 3), ('owner-reloaded-three', 3),
                  ('owner-cleared', 0), ('owner-reloaded-clear', 0)]
        def card(slug, rating, picker=False):
            return {'href': '/events/goose-charleston-pour-house-' + slug + '/',
                    'on': rating, 'stars': 5 if rating or picker else 0,
                    'toggle': None if rating or picker else 'Rate',
                    'selected': f'{rating} stars' if rating else None, 'error': None}
        for label, rating in stages:
            rows.append({'label': label, 'viewer_id': 711,
                         'server': {'ratings': {'102': 5, **({'101': rating} if rating else {})}},
                         'value': {'owner': '1', 'note': 'Only you see your ratings.',
                                   'cards': [card('fall', rating, label == 'owner-picker'), card('summer', 5)]}})
        for persona, viewer in [('visitor', 0), ('other', 712)]:
            for surface in ['history', 'event', 'artist']:
                rows.append({'label': f'{persona}-{surface}', 'viewer_id': viewer,
                             'server': {'ratings': {'102': 5}},
                             'value': {'ratingNodes': 0, 'hasGoose': True, 'owner': '0', 'userId': '711',
                                       'cards': [card('fall', 0), card('summer', 0)]}})
        return rows

    def test_happy_path(self):
        result = self.grade(self.observations())
        self.assertEqual(result['passed'], 15)
        self.assertEqual(result['findings'], 0)

    def test_missing_observations_fail_closed(self):
        self.assertEqual(self.grade([])['findings'], 15)

    def test_regressions_are_findings(self):
        rows = self.observations()
        rows[0]['value']['cards'][1]['on'] = 0  # Seed hydration regression.
        rows[2]['server']['ratings']['101'] = 3  # Optimistic UI without save.
        rows[4]['value']['cards'][0]['on'] = 0  # Reload loses rating.
        rows[6]['server']['ratings']['101'] = 3  # Clear did not persist.
        rows[-1]['value']['ratingNodes'] = 1  # Public leakage.
        rows[-2]['viewer_id'] = 711  # Wrong browser identity.
        self.assertEqual(self.grade(rows)['findings'], 6)


if __name__ == '__main__':
    unittest.main()
