#!/usr/bin/env bash
# bin/stamp-version.sh — single version source for the GenD Society plugin.
#
# GEND_SOCIETY_VERSION in gend-society.php is the ONLY hand-edited version. WordPress
# reads plugin/theme headers statically (it never executes PHP for them), so
# the header copies cannot be derived at runtime; this script stamps them and
# `check` (run by CI on every push/PR) fails when anything disagrees.
#
#   bin/stamp-version.sh set <ver> [--force]   rewrite GEND_SOCIETY_VERSION, then stamp
#   bin/stamp-version.sh stamp                 copy GEND_SOCIETY_VERSION into every header
#   bin/stamp-version.sh check                 verify headers + scan cache-busters
#   bin/stamp-version.sh --help
#
# Stamped locations:
#   gend-society.php                        " * Version:" (first docblock only)
#   readme.txt                              "Stable tag:"
#   themes/gend-society-theme/style.css     "Version:" (first match; functions.php
#                                           enqueues the theme stylesheet with
#                                           wp_get_theme()->get( 'Version' ))
#   themes/gend-society-theme/readme.txt    "Stable tag:"
#
# Cache-buster scan (check): every *.php / *.js outside handoff/, themes/,
# .git/, node_modules/ (whole-line comments ignored). Enqueue/register calls and `$...ver... =` assignments
# are joined across lines (up to `);` / `;`) before testing. Rules:
#   1   literal query-string version: ?ver=<digit> / ?v=<digit>
#   1b  ?ver= built from a bare filemtime: '?ver=' . filemtime(
#   1c  a line echoing ?v= / ?ver= that uses filemtime( without GEND_SOCIETY_VERSION
#   2   a quoted semver in a `$...ver... =` assignment (e.g. $ver = '2.1.0',
#       or a dead `defined('GEND_SOCIETY_VERSION') ? GEND_SOCIETY_VERSION : '1.0.0'` fallback)
#   3   enqueue/register call with a quoted semver literal and no http(s) src
#       (CDN library pins such as chart.js 4.4.1 are library versions -> allowed)
#   4   filemtime( used as a version (enqueue arg or `$...ver... =` assignment)
#       that is not derived from GEND_SOCIETY_VERSION.
#       GS-derived variable tracking: a variable becomes GS-derived when its
#       right-hand side contains GEND_SOCIETY_VERSION or starts with a GS-derived variable.
#       An assignment passes when its RHS STARTS with GEND_SOCIETY_VERSION / a GS-derived
#       variable, or every filemtime( in it is immediately preceded by
#       `GEND_SOCIETY_VERSION . '.' .` (or `<gs-var> .`). Containing GEND_SOCIETY_VERSION somewhere
#       else (e.g. only in a ternary fallback) does NOT pass.
#       Tracking is per FILE, not per function: a slightly looser check, accepted.
#
# ALLOWLIST — "file|offending token" (whitespace-normalised substring match on
# the offending statement). A NEW hit in the same file still fails.
ALLOWLIST=(
  # Hub-hosted cross-origin leo plugin JS; must track leo's files, which a
  # container's GEND_SOCIETY_VERSION cannot see.
  "inc/ai-widget.php|'2.1.0'"
  # Foreign blog-manager / gend-media-optimizer admin CSS; each file's own
  # mtime is the exact cache signal.
  "inc/dashboard-hosting.php|(string) filemtime( \$gmo_css_path )"
  # projects plugin psoo-bp assets; versioned by the projects plugin's PSOO_VER.
  "inc/dashboard-remote-membership.php|\$gs_bp_ver = PSOO_VER"
)

set -euo pipefail
cd "$(dirname "$0")/.."

SEMVER_RE='^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$'
MAIN=gend-society.php
README=readme.txt
THEME_CSS=themes/gend-society-theme/style.css
THEME_README=themes/gend-society-theme/readme.txt

usage() {
  sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'
}

read_version() {
  local v n
  v=$(sed -nE "s/^define\(\s*'GEND_SOCIETY_VERSION'\s*,\s*'([^']+)'\s*\);.*/\1/p" "$MAIN")
  n=$(printf '%s\n' "$v" | grep -c . || true)
  if [ "$n" -ne 1 ] || ! printf '%s' "$v" | grep -Eq "$SEMVER_RE"; then
    echo "ERROR: expected exactly one semver define('GEND_SOCIETY_VERSION', ...) in $MAIN, got: '${v}'" >&2
    exit 2
  fi
  printf '%s' "$v"
}

# perl in-place edit with a backup suffix (portable to msys/Git Bash perl).
pi() { # pi <perl-expr> <file>
  perl -i.bak -pe "$1" "$2" && rm -f "$2.bak"
}

do_stamp() {
  local ver; ver=$(read_version)
  export STAMP_VER="$ver"
  # Plugin header: first " * Version:" inside the first docblock only.
  pi 'if (!$done && !$end && s/^(\s\*\s*Version:\s+).*/$1$ENV{STAMP_VER}/) { $done = 1 } $end = 1 if m{\*/};' "$MAIN"
  if [ -f "$README" ]; then
    pi 's/^(Stable tag:\s*).*/$1$ENV{STAMP_VER}/' "$README"
  else
    echo "notice: $README absent, skipped"
  fi
  pi 'if (!$done && s/^(Version:\s*).*/$1$ENV{STAMP_VER}/) { $done = 1 }' "$THEME_CSS"
  pi 's/^(Stable tag:\s*).*/$1$ENV{STAMP_VER}/' "$THEME_README"
  echo "stamped $ver"
}

do_set() {
  local new="${1:-}" force="${2:-}"
  if ! printf '%s' "$new" | grep -Eq "$SEMVER_RE"; then
    echo "ERROR: '$new' is not a version (x.y.z[-suffix])" >&2; exit 2
  fi
  local cur; cur=$(read_version)
  if [ "$force" != "--force" ]; then
    local top; top=$(printf '%s\n%s\n' "$cur" "$new" | sort -V | tail -n1)
    if [ "$new" = "$cur" ] || [ "$top" != "$new" ]; then
      echo "ERROR: $new is not above current GEND_SOCIETY_VERSION $cur (use --force)" >&2; exit 2
    fi
  fi
  export STAMP_VER="$new"
  pi "s/^(define\(\s*'GEND_SOCIETY_VERSION'\s*,\s*')[^']+('\s*\);)/\$1\$ENV{STAMP_VER}\$2/" "$MAIN"
  do_stamp
}

first_value() { # first_value <file> <perl-regex capturing value> [docblock-only]
  [ -f "$1" ] || { echo "(missing)"; return; }
  perl -ne 'BEGIN{$re=shift @ARGV; $doc=shift @ARGV} if (/$re/) { my $v = $1; $v =~ s/\s+$//; print $v; exit } exit if $doc && m{\*/};' "$2" "${3:-0}" "$1"
}

scan_cache_busters() {
  local files
  files=$(find . \( -path ./.git -o -path ./handoff -o -path ./themes -o -path ./dist -o -path ./vendor -o -path ./.cache -o -name node_modules \) -prune -o \
          -type f \( -name '*.php' -o -name '*.js' \) -print | sed 's#^\./##' | sort)
  # shellcheck disable=SC2086
  printf '%s\n' "${ALLOWLIST[@]}" | perl -e '
    use strict; use warnings;
    my @allow;
    while (my $l = <STDIN>) {
      chomp $l; next unless length $l;
      my ($f, $tok) = split /\|/, $l, 2;
      (my $n = $tok) =~ s/\s+/ /g;
      push @allow, [$f, $n];
    }
    my $reasons = {
      "inc/ai-widget.php" => "hub-hosted leo assets (track leo, not GEND_SOCIETY_VERSION)",
      "inc/dashboard-hosting.php" => "foreign blog-manager/gend-media-optimizer CSS (own mtime)",
      "inc/dashboard-remote-membership.php" => "projects plugin assets (PSOO_VER)",
    };
    my ($bad, $ok) = (0, 0);
    my $semq = qr/[\x27"][0-9]+\.[0-9]+(?:\.[0-9]+)?[^\x27"]*[\x27"]/;
    for my $file (@ARGV) {
      open my $fh, "<", $file or die "$file: $!";
      local $/; my $src = <$fh>; close $fh;
      # Blank whole-line comments (//, #, /*, * docblock lines) in place so
      # offsets/line numbers survive and prose never forms a fake statement.
      $src =~ s{^([ \t]*)((?://|#|/\*|\*).*)$}{$1 . (" " x length($2))}gme;
      my @ev;  # [offset, kind, text, extra]
      # line rules
      my $off = 0;
      for my $line (split /\n/, $src, -1) {
        if ($line =~ /\?v(?:er)?=[\x27"]?[0-9]/) { push @ev, [$off, "rule1 literal ?ver=", $line] }
        if ($line =~ /\?ver=[\x27"]?\s*\.\s*\@?filemtime\(/) { push @ev, [$off, "rule1b ?ver= from bare filemtime", $line] }
        elsif ($line =~ /\?v(?:er)?=/ && $line =~ /filemtime\(/ && $line !~ /GEND_SOCIETY_VERSION/) { push @ev, [$off, "rule1c echoed ?v= from filemtime without GEND_SOCIETY_VERSION", $line] }
        $off += length($line) + 1;
      }
      if ($file =~ /\.php$/) {
        while ($src =~ /\$([A-Za-z_0-9]*ver[A-Za-z_0-9]*)\s*=(?![=>])\s*([^;]*);/g) {
          push @ev, [$-[0], "assign", $&, [$1, $2]];
          pos($src) = $-[0] + 1;
        }
        while ($src =~ /\bwp_(?:enqueue|register)_(?:style|script)\s*\((.*?)\)\s*;/gs) {
          push @ev, [$-[0], "enqueue", $&, $1];
        }
      }
      @ev = sort { $a->[0] <=> $b->[0] } @ev;
      my %gs;
      my $gsalt = sub { my @v = sort keys %gs; return @v ? "|" . join("|", map { "\\\$\Q$_\E" } @v) : "" };
      my $fm_ok = sub {
        my ($t) = @_; my $alt = "GEND_SOCIETY_VERSION" . $gsalt->();
        while ($t =~ /\@?filemtime\(/g) {
          my $pre = substr($t, 0, $-[0]);
          return 0 unless $pre =~ /(?:$alt)\s*\.\s*(?:[\x27"]\.[\x27"]\s*\.\s*)?$/;
        }
        return 1;
      };
      for my $e (@ev) {
        my ($o, $kind, $text, $x) = @$e;
        my @hits;
        if ($kind eq "assign") {
          my ($name, $rhs) = @$x;
          $rhs =~ s/^\s+|\s+$//g;
          my $alt = "GEND_SOCIETY_VERSION" . $gsalt->();
          push @hits, "rule2 quoted semver in \$$name assignment" if $rhs =~ $semq;
          if ($rhs =~ /filemtime\(/) {
            push @hits, "rule4 \$$name from filemtime without GEND_SOCIETY_VERSION"
              unless $rhs =~ /^(?:$alt)\b/ || $fm_ok->($rhs);
          }
          if ($rhs =~ /GEND_SOCIETY_VERSION/ || $rhs =~ /^(?:$alt)\b/) { $gs{$name} = 1 } else { delete $gs{$name} }
        } elsif ($kind eq "enqueue") {
          push @hits, "rule3 enqueue with quoted semver literal" if $x =~ $semq && $x !~ m{https?://};
          push @hits, "rule4 enqueue version from bare filemtime" if $x =~ /filemtime\(/ && !$fm_ok->($x);
        } else {
          push @hits, $kind;
        }
        next unless @hits;
        my $ln = 1 + (substr($src, 0, $o) =~ tr/\n//);
        (my $norm = $text) =~ s/\s+/ /g;
        my $allowed = 0;
        for my $a (@allow) { if ($a->[0] eq $file && index($norm, $a->[1]) >= 0) { $allowed = 1; last } }
        for my $h (@hits) {
          if ($allowed) { print "ALLOWED $file:$ln $h -- $reasons->{$file}\n"; $ok++ }
          else { print "$file:$ln: $h\n"; $bad++ }
        }
      }
    }
    print "cache-buster scan: $bad violation(s), $ok allowlisted\n";
    exit($bad ? 1 : 0);
  ' $files
}

do_check() {
  local ver; ver=$(read_version)
  local rc=0 row loc val
  printf '%-48s | %s\n' "location" "value"
  printf '%-48s-+-%s\n' "------------------------------------------------" "--------"
  printf '%-48s | %s\n' "$MAIN GEND_SOCIETY_VERSION" "$ver"
  for row in \
    "$MAIN header Version|$(first_value "$MAIN" '^\s\*\s*Version:\s*(.*)' 1)" \
    "$README Stable tag|$(first_value "$README" '^Stable tag:\s*(.*)')" \
    "$THEME_CSS Version|$(first_value "$THEME_CSS" '^Version:\s*(.*)')" \
    "$THEME_README Stable tag|$(first_value "$THEME_README" '^Stable tag:\s*(.*)')"; do
    loc="${row%%|*}"; val="${row#*|}"
    if [ "$val" = "$ver" ]; then
      printf '%-48s | %s\n' "$loc" "$val"
    else
      printf '%-48s | %s   <-- MISMATCH (GEND_SOCIETY_VERSION %s)\n' "$loc" "$val" "$ver"; rc=1
    fi
  done
  echo
  scan_cache_busters || rc=1
  if [ "$rc" -ne 0 ]; then echo "check: FAIL"; else echo "check: OK ($ver)"; fi
  return "$rc"
}

case "${1:-}" in
  set)   shift; do_set "${1:-}" "${2:-}" ;;
  stamp) do_stamp ;;
  check) do_check ;;
  -h|--help|help) usage ;;
  *) usage >&2; exit 2 ;;
esac
