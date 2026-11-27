//go:build !windows

package main

import "errors"

func startAppWindow(_ string, _ Layout, url string, _ bool, _ func(error)) error {
	return OpenBrowser(url)
}

func runAppWindow(_ string) error {
	return errors.New("the desktop window is only available on Windows")
}

func closeAppWindow(_ string) error {
	return nil
}
