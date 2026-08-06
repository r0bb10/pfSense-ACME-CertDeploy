package control

import (
	"bufio"
	"encoding/json"
	"fmt"
	"net"
)

// Client sends commands to the daemon over its Unix socket.
type Client struct {
	socket string
}

func New(socket string) *Client {
	return &Client{socket: socket}
}

func (c *Client) Exec(args ...string) error {
	conn, err := net.Dial("unix", c.socket)
	if err != nil {
		return err
	}
	defer func() {
		_ = conn.Close()
	}()

	req := map[string]interface{}{"args": args}
	enc := json.NewEncoder(conn)
	if err := enc.Encode(req); err != nil {
		return err
	}

	// Read line-by-line response from daemon.
	scanner := bufio.NewScanner(conn)
	for scanner.Scan() {
		line := scanner.Text()
		fmt.Println(line)
	}
	if err := scanner.Err(); err != nil {
		return err
	}
	return nil
}
