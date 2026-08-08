package deploy

import "testing"

func TestValidateArtifacts(t *testing.T) {
	tests := []struct {
		name     string
		artifact Artifact
		wantErr  bool
	}{
		{name: "certificate artifact", artifact: Artifact{Source: "site.fullchain", Filename: "fullchain.pem"}},
		{name: "source traversal", artifact: Artifact{Source: "../../cf/conf/config.xml", Filename: "config.xml"}, wantErr: true},
		{name: "destination traversal", artifact: Artifact{Source: "site.key", Filename: "../key"}, wantErr: true},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			err := validateArtifacts(Deployment{CertificateName: "site", Artifacts: []Artifact{tt.artifact}})
			if (err != nil) != tt.wantErr {
				t.Fatalf("validateArtifacts() error = %v, wantErr %t", err, tt.wantErr)
			}
		})
	}
}
