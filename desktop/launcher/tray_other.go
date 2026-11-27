//go:build !windows

package main

func startTray(_ string, _, _ func(), _ func(error)) (func(), error) {
	return func() {}, nil
}
