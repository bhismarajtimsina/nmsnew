UPDATE users SET
                 is_twofa = 0,
                 twofa_token = null,
                 twofa_remember = 0,
                 twofa_attempt = 0;