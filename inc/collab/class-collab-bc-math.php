<?php
/**
 * gend-society — LMSR fixed-point money-math core (Phase 86-01, v12.0).
 *
 * The numerically-stable bcmath foundation for the GenD Match prediction-market
 * engine. bcmath has NO native exp/ln/ceil/floor (PHP BC Math manual: only
 * add/sub/mul/div/pow/sqrt/comp/mod) — this class pins the VETTED range-reduced
 * Maclaurin/atanh algorithm ONCE so the engine's quote-path (86-03) and the
 * crown UAT (86-05) route through IDENTICAL math (86-RESEARCH Q1, Pitfall
 * "quote-path vs settle-path rounding mismatch").
 *
 * HARD RULE (MARKET-02, locked CONTEXT decision): NEVER native PHP float,
 * (float) cast, floatval(), exp() or log() anywhere in the money leg. Every
 * transcendental is computed in bcmath decimal strings. The k = round(x/ln2)
 * range-reduction step is done with a bcmath bc_round_int() helper, NOT PHP
 * round() on a float.
 *
 * Provides:
 *   - bc_exp(x)  : e^x via k=round(x/ln2), r=x-k*ln2 (|r|<=ln2/2), Maclaurin, *2^k
 *   - bc_ln(x)   : ln x via range reduction by e + atanh series on m near 1
 *   - bc_max(a,b): bccomp-based
 *   - bc_ceil(x) : round UP  to the integer DGEN atom (maker-favor: BUY cost)
 *   - bc_floor(x): round DOWN to the integer DGEN atom (maker-favor: PAYOUT)
 *   - price(q_yes,q_no,b) : log-sum-exp implied probability (MARKET-04); p_yes+p_no==1
 *   - cost(q_yes,q_no,b)  : C(q)=b*(m+ln(sum)) log-sum-exp stabilized (MARKET-02)
 *
 * Log-sum-exp stabilization (MANDATORY): subtract the max exponent before
 * exponentiating so every bc_exp argument is <= 0 (exp(0)=1, exp(<0) in (0,1]),
 * making overflow impossible.
 *
 * Maker-favor rounding (86-RESEARCH Q1 "Rounding discipline"): a BUY cost is
 * rounded UP (bc_ceil) so the bettor pays >= exact and escrow collects >= what
 * the escrow-invariant assumes; a PAYOUT is rounded DOWN (bc_floor) so the
 * house pays <= exact. Accumulated rounding dust can therefore only GROW escrow,
 * never shrink it — bounded-loss can never be broken by rounding.
 *
 * This class is PURE MATH. It moves no DGEN, touches no DB, registers no route.
 * Integer-DGEN rounding (bc_ceil / bc_floor) of a trade's cost is APPLIED by the
 * engine (86-03) around cost(q') - cost(q); cost()/price() themselves return
 * full-scale fixed-point strings. The entrypoint require of this class is added
 * in 86-04 alongside the engine (pure helper — nothing loads it in 86-01).
 *
 * q_yes/q_no are scaled-integer micro-shares (1e6 micro-shares per whole share,
 * mirroring the `micro` convention in contracts-and-payments/class-ydgen-ledger.php);
 * `b` is a scaled integer too. The ratio q_i/b is a pure fraction, so the chosen
 * share scale cancels in the exponent — price()/cost() are scale-convention-agnostic.
 *
 * @package gend-society
 * @since   v12.0 (Phase 86)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Gend_GS_BC_Math' ) ) {

	/**
	 * Fixed-point LMSR math primitives (bcmath, no native float/exp/log).
	 */
	class Gend_GS_BC_Math {

		/**
		 * Public working scale for LMSR quotes/prices (decimal digits).
		 *
		 * Quote-path and settle-path MUST share this single constant so escrowed
		 * never diverges from owed (86-RESEARCH Pitfall 2). 18 digits leaves ~6
		 * guard digits beyond any realistic DGEN magnitude before maker-favor
		 * rounding truncates to the integer DGEN atom.
		 */
		const SCALE = 18;

		/**
		 * Extra guard digits run INTERNALLY during each series before the final
		 * answer is rounded back down to the requested scale (avoids the last
		 * digit drifting from accumulated series truncation).
		 */
		const GUARD = 10;

		/**
		 * e (Euler's number) to 40 decimal places — a timeless constant.
		 * Used by bc_ln range reduction (pull out integer powers of e).
		 */
		const E = '2.7182818284590452353602874713526624977572';

		/**
		 * ln(2) to 40 decimal places — a timeless constant.
		 * Used by bc_exp range reduction (k = round(x/ln2), r = x - k*ln2).
		 */
		const LN2 = '0.6931471805599453094172321214581765680755';

		/* -----------------------------------------------------------------
		 * Internal helpers
		 * ----------------------------------------------------------------- */

		/**
		 * bcmath absolute value (string in, string out). No float.
		 *
		 * @param string $x Decimal string.
		 * @param int    $scale Working scale.
		 * @return string |x|
		 */
		private static function bc_abs( $x, $scale ) {
			if ( bccomp( $x, '0', $scale ) < 0 ) {
				return bcmul( $x, '-1', $scale );
			}
			return $x;
		}

		/**
		 * Round a bcmath decimal string to the NEAREST integer (ties away from
		 * zero), done ENTIRELY in bcmath — this is the float-free replacement for
		 * PHP round() used by bc_exp's k = round(x/ln2) range-reduction step.
		 *
		 * @param string $x Decimal string.
		 * @return string Integer string (scale 0).
		 */
		private static function bc_round_int( $x ) {
			if ( bccomp( $x, '0', self::SCALE ) >= 0 ) {
				// floor(x + 0.5)
				return self::bc_floor( bcadd( $x, '0.5', self::SCALE + self::GUARD ) );
			}
			// ceil(x - 0.5) for negatives (ties away from zero, symmetric).
			return self::bc_ceil( bcsub( $x, '0.5', self::SCALE + self::GUARD ) );
		}

		/* -----------------------------------------------------------------
		 * Rounding primitives (maker-favor) — NOT native bcmath.
		 *
		 * bc_ceil  : rounds toward +infinity. Used for BUY cost (the bettor pays
		 *            >= the exact LMSR cost) so escrow collects at least what the
		 *            escrow-invariant assumes.
		 * bc_floor : rounds toward -infinity. Used for PAYOUT (the house pays <=
		 *            the exact amount).
		 * Together these guarantee accumulated rounding can only GROW escrow,
		 * never shrink it — bounded-loss is un-violate-able by rounding
		 * (86-RESEARCH Q1 "Rounding discipline"). Hand-rolled via bcadd/bccomp on
		 * the fractional part; NO composer dependency.
		 * ----------------------------------------------------------------- */

		/**
		 * Round UP to the integer DGEN atom (toward +infinity).
		 *
		 * @param string $x Decimal string.
		 * @return string Integer string.
		 */
		public static function bc_ceil( $x ) {
			$x = (string) $x;
			// Truncate toward zero to get the integer part.
			$int = bcadd( $x, '0', 0 );
			if ( bccomp( $x, $int, self::SCALE + self::GUARD ) === 0 ) {
				// Already an integer.
				return $int;
			}
			if ( bccomp( $x, '0', self::SCALE + self::GUARD ) > 0 ) {
				// Positive with a fractional part → next integer up.
				return bcadd( $int, '1', 0 );
			}
			// Negative: truncation toward zero already rounded up (toward +inf).
			return $int;
		}

		/**
		 * Round DOWN to the integer DGEN atom (toward -infinity).
		 *
		 * @param string $x Decimal string.
		 * @return string Integer string.
		 */
		public static function bc_floor( $x ) {
			$x = (string) $x;
			$int = bcadd( $x, '0', 0 );
			if ( bccomp( $x, $int, self::SCALE + self::GUARD ) === 0 ) {
				return $int;
			}
			if ( bccomp( $x, '0', self::SCALE + self::GUARD ) < 0 ) {
				// Negative with a fractional part → next integer down.
				return bcsub( $int, '1', 0 );
			}
			// Positive: truncation toward zero already rounded down (toward -inf).
			return $int;
		}

		/**
		 * bccomp-based maximum of two decimal strings.
		 *
		 * @param string $a Decimal string.
		 * @param string $b Decimal string.
		 * @return string max(a, b)
		 */
		public static function bc_max( $a, $b ) {
			return ( bccomp( (string) $a, (string) $b, self::SCALE + self::GUARD ) >= 0 )
				? (string) $a
				: (string) $b;
		}

		/* -----------------------------------------------------------------
		 * Transcendentals — bcmath, NO native exp()/log()/(float).
		 * ----------------------------------------------------------------- */

		/**
		 * e^x via range reduction + Maclaurin (86-RESEARCH Q1).
		 *
		 * 1. k = round(x / ln2)  (bcmath round);  r = x - k*ln2  → |r| <= ln2/2 ≈ 0.3466.
		 * 2. e^r = Σ r^n/n!  accumulating term_n = term_{n-1} * r / n, stopping
		 *    when |term| < 10^-(scale+GUARD). ~20 terms give scale-18 headroom.
		 * 3. Multiply by 2^k via bcpow(2, |k|); bcdiv if k is negative.
		 *
		 * After log-sum-exp (see price()/cost()) the argument is ALWAYS <= 0, so
		 * overflow is impossible.
		 *
		 * @param string $x     Exponent as a decimal string.
		 * @param int    $scale Requested output scale (defaults to SCALE).
		 * @return string e^x at the requested scale.
		 */
		public static function bc_exp( $x, $scale = self::SCALE ) {
			$x = (string) $x;
			$s = $scale + self::GUARD;

			// 1. Range reduce: k = round(x / ln2), r = x - k*ln2.
			$k = self::bc_round_int( bcdiv( $x, self::LN2, $s ) );
			$r = bcsub( $x, bcmul( $k, self::LN2, $s ), $s );

			// 2. Maclaurin on the small r: Σ r^n/n!.
			$term    = '1';                 // n = 0 term = 1
			$sum     = '1';
			$epsilon = bcpow( '10', (string) ( -1 * $s ), $s ); // 10^-(scale+guard)
			for ( $n = 1; $n <= 60; $n++ ) {
				// term_n = term_{n-1} * r / n
				$term = bcdiv( bcmul( $term, $r, $s ), (string) $n, $s );
				$sum  = bcadd( $sum, $term, $s );
				if ( bccomp( self::bc_abs( $term, $s ), $epsilon, $s ) < 0 ) {
					break;
				}
			}

			// 3. Multiply by 2^k (bcpow needs a non-negative integer exponent).
			$k_int = (int) $k;
			if ( 0 === $k_int ) {
				$result = $sum;
			} elseif ( $k_int > 0 ) {
				$result = bcmul( $sum, bcpow( '2', (string) $k_int, $s ), $s );
			} else {
				$result = bcdiv( $sum, bcpow( '2', (string) ( -1 * $k_int ), $s ), $s );
			}

			// Round the guarded result down to the requested scale.
			return self::bc_scale( $result, $scale );
		}

		/**
		 * Natural log via range reduction + atanh series (86-RESEARCH Q1).
		 *
		 * 1. require x > 0 (throws InvalidArgumentException otherwise — the UAT
		 *    asserts the throw).
		 * 2. Range-reduce: count integer k, multiplying/dividing by e, until the
		 *    reduced m = x / e^k sits in a tight band near 1 (0.6 <= m <= 1.7),
		 *    where the atanh series converges fast.
		 * 3. ln(m) = 2*(y + y^3/3 + y^5/5 + ...), y = (m-1)/(m+1) (|y| small).
		 * 4. Result = k + ln(m).
		 *
		 * @param string $x     Positive decimal string.
		 * @param int    $scale Requested output scale (defaults to SCALE).
		 * @return string ln(x) at the requested scale.
		 * @throws InvalidArgumentException When x <= 0.
		 */
		public static function bc_ln( $x, $scale = self::SCALE ) {
			$x = (string) $x;
			$s = $scale + self::GUARD;

			if ( bccomp( $x, '0', $s ) <= 0 ) {
				throw new InvalidArgumentException( 'Gend_GS_BC_Math::bc_ln requires x > 0' );
			}

			$e     = self::bc_scale( self::E, $s );
			$k     = 0;
			$m     = $x;
			$lower = '0.6';
			$upper = '1.7';

			// Reduce down while m too large.
			while ( bccomp( $m, $upper, $s ) > 0 ) {
				$m = bcdiv( $m, $e, $s );
				$k++;
			}
			// Reduce up while m too small.
			while ( bccomp( $m, $lower, $s ) < 0 ) {
				$m = bcmul( $m, $e, $s );
				$k--;
			}

			// 3. atanh series: ln(m) = 2 * ( y + y^3/3 + y^5/5 + ... ), y=(m-1)/(m+1).
			$y      = bcdiv( bcsub( $m, '1', $s ), bcadd( $m, '1', $s ), $s );
			$y2     = bcmul( $y, $y, $s );
			$term   = $y;                   // first term (n = 1)
			$series = $y;
			$epsilon = bcpow( '10', (string) ( -1 * $s ), $s );
			for ( $n = 3; $n <= 199; $n += 2 ) {
				// term_next = term_prev * y^2 * (previous odd)/(current odd) — but
				// simplest correct form: term_n = y^n / n; build y^n incrementally.
				$term = bcmul( $term, $y2, $s ); // now y^n (n odd), missing the 1/n scaling
				$add  = bcdiv( $term, (string) $n, $s );
				$series = bcadd( $series, $add, $s );
				if ( bccomp( self::bc_abs( $add, $s ), $epsilon, $s ) < 0 ) {
					break;
				}
			}
			$ln_m = bcmul( '2', $series, $s );

			// 4. Result = k + ln(m).
			$result = bcadd( (string) $k, $ln_m, $s );

			return self::bc_scale( $result, $scale );
		}

		/**
		 * Truncate a decimal string to a given scale (no rounding — bcmath's
		 * native truncation via a scale-0-preserving add). Kept private so the
		 * public API always returns SCALE-clean strings.
		 *
		 * @param string $x     Decimal string.
		 * @param int    $scale Target scale.
		 * @return string
		 */
		private static function bc_scale( $x, $scale ) {
			return bcadd( (string) $x, '0', $scale );
		}

		/* -----------------------------------------------------------------
		 * LMSR money functions (log-sum-exp stabilized) — added in Task 2.
		 * ----------------------------------------------------------------- */

		/**
		 * Shared log-sum-exp core for price() and cost() — the SINGLE source of
		 * the exponential math so the two functions can NEVER diverge (Pitfall
		 * "two copies of the cost math").
		 *
		 * Computes, at working scale S:
		 *   u_yes = q_yes/b,  u_no = q_no/b
		 *   m     = max(u_yes, u_no)
		 *   e_yes = exp(u_yes - m)   in (0,1]
		 *   e_no  = exp(u_no  - m)   in (0,1]
		 *   sum   = e_yes + e_no
		 * Every exp argument is <= 0 by construction (subtract the max), so
		 * overflow is impossible.
		 *
		 * @param string $q_yes YES micro-shares.
		 * @param string $q_no  NO micro-shares.
		 * @param string $b     Liquidity depth (scaled integer).
		 * @return array{u_yes:string,u_no:string,m:string,e_yes:string,e_no:string,sum:string}
		 * @throws InvalidArgumentException When b <= 0.
		 */
		private static function _lse( $q_yes, $q_no, $b ) {
			$S = self::SCALE;
			if ( bccomp( (string) $b, '0', $S ) <= 0 ) {
				throw new InvalidArgumentException( 'Gend_GS_BC_Math::_lse requires b > 0' );
			}

			$u_yes = bcdiv( (string) $q_yes, (string) $b, $S );
			$u_no  = bcdiv( (string) $q_no, (string) $b, $S );
			$m     = self::bc_max( $u_yes, $u_no );

			$e_yes = self::bc_exp( bcsub( $u_yes, $m, $S ), $S );
			$e_no  = self::bc_exp( bcsub( $u_no, $m, $S ), $S );
			$sum   = bcadd( $e_yes, $e_no, $S );

			return array(
				'u_yes' => $u_yes,
				'u_no'  => $u_no,
				'm'     => $m,
				'e_yes' => $e_yes,
				'e_no'  => $e_no,
				'sum'   => $sum,
			);
		}

		/**
		 * LMSR implied probability (MARKET-04) — live YES/NO odds before a stake.
		 *
		 * p_yes = e_yes / sum;  p_no = 1 - p_yes (exact by construction, so
		 * p_yes + p_no == 1 in fixed-point — the UAT asserts
		 * bccomp(bcadd(p_yes,p_no,S),'1',9) === 0).
		 *
		 * Read-only. NO rounding to the DGEN atom — probabilities are fractions
		 * in (0,1), returned as full-SCALE fixed-point strings.
		 *
		 * @param string $q_yes YES micro-shares.
		 * @param string $q_no  NO micro-shares.
		 * @param string $b     Liquidity depth.
		 * @return array{p_yes:string,p_no:string}
		 */
		public static function price( $q_yes, $q_no, $b ) {
			$S = self::SCALE;
			$l = self::_lse( $q_yes, $q_no, $b );

			$p_yes = bcdiv( $l['e_yes'], $l['sum'], $S );
			$p_no  = bcsub( '1', $p_yes, $S );

			return array(
				'p_yes' => $p_yes,
				'p_no'  => $p_no,
			);
		}

		/**
		 * LMSR cost potential (MARKET-02): C(q) = b * ( m + ln(sum) ), using the
		 * SAME u/m/e/sum as price() via _lse() so quote and settle never diverge.
		 *
		 * This is the raw cost potential; the engine (86-03) computes a trade's
		 * cost as cost(q') - cost(q) and then applies maker-favor rounding
		 * (bc_ceil on a BUY, bc_floor on a PAYOUT) to reach the integer DGEN atom.
		 * This method does NOT settle DGEN and does NOT round to the atom — it is
		 * pure fixed-point math at SCALE.
		 *
		 * @param string $q_yes YES micro-shares.
		 * @param string $q_no  NO micro-shares.
		 * @param string $b     Liquidity depth.
		 * @return string C(q) at SCALE.
		 */
		public static function cost( $q_yes, $q_no, $b ) {
			$S = self::SCALE;
			$l = self::_lse( $q_yes, $q_no, $b );

			// C = b * ( m + ln(sum) ).
			$inner = bcadd( $l['m'], self::bc_ln( $l['sum'], $S ), $S );
			return bcmul( (string) $b, $inner, $S );
		}
	}
}
